<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Access\RecordAccessVoter;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Routing\RouteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

final class PageResolver
{
    public function __construct(
        private readonly SiteMatcher $siteMatcher,
        private readonly SiteFinder $sites,
        private readonly ConnectionPool $connections,
        private readonly RecordAccessVoter $access,
        private readonly PageIdentity $identities,
    ) {}

    public function resolve(mixed $input, bool $byReference, array $allowedSites, string $instance, array $publicAliases = []): array
    {
        // A fresh anonymous live context prevents backend cookies and preview state granting access.
        $context = new Context();
        try {
            $query = '';
            $fragment = '';
            if ($byReference) {
                if (!is_array($input) || !PeerConfiguration::isUuid($input['page'] ?? null)
                    || ($input['instance'] ?? null) !== $instance
                    || !is_int($input['language'] ?? null) || $input['language'] < 0
                ) {
                    return ['status' => 'unsupported'];
                }
                $pageId = $this->identities->findPage($input['page']);
                if ($pageId === null) {
                    return ['status' => 'unavailable'];
                }
                $site = $this->sites->getSiteByPageId($pageId);
                $language = $site->getLanguageById($input['language']);
            } else {
                if (!is_string($input)) {
                    return ['status' => 'unsupported'];
                }
                PeerConfiguration::origin($input);
                $uri = new Uri(PeerConfiguration::canonicalUrl($input, $publicAliases));
                $query = $uri->getQuery();
                $fragment = $uri->getFragment();
                // Plugin, preview, authentication and page-selection parameters aren't page-only URLs.
                foreach (explode('&', $query) as $parameter) {
                    $key = rawurldecode(explode('=', $parameter, 2)[0]);
                    if (preg_match('/^(id|L|type|cHash|no_cache|eID|ADMCMD.*|tx_.*|.*token.*|.*password.*|auth.*|redirect.*)$/iD', $key)
                        || preg_match('/[\x00-\x20\x7f\[\]]/', $key)
                    ) {
                        return ['status' => 'unsupported'];
                    }
                }
                $request = new ServerRequest($uri->withQuery('')->withFragment(''), 'GET');
                // SiteMatcher is internal in TYPO3 13/14; isolate it here and cover both versions.
                $match = $this->siteMatcher->matchRequest($request);
                if (!$match->getSite() instanceof Site && $uri->getScheme() === 'http' && $uri->getPort() === null) {
                    $request = $request->withUri($request->getUri()->withScheme('https'));
                    $match = $this->siteMatcher->matchRequest($request);
                }
                $site = $match->getSite();
                $language = $match->getLanguage();
                if (!$site instanceof Site || $language === null || !in_array($site->getIdentifier(), $allowedSites, true)) {
                    return ['status' => 'unavailable'];
                }
                $route = $site->getRouter($context)->matchRequest($request, $match);
                if (!$route instanceof PageArguments || $route->getPageType() !== '0' || $route->getRouteArguments() !== []) {
                    return ['status' => 'unsupported'];
                }
                $pageId = $route->getPageId();
                // Enforce the actual page's site as well, including nested site roots.
                if ($this->sites->getSiteByPageId($pageId)->getIdentifier() !== $site->getIdentifier()) {
                    return ['status' => 'unavailable'];
                }
            }
            if (!in_array($site->getIdentifier(), $allowedSites, true) || !$language->isEnabled()) {
                return ['status' => 'unavailable'];
            }
            $context->setAspect('language', new LanguageAspect($language->getLanguageId(), $language->getLanguageId(), LanguageAspect::OVERLAYS_ON, []));
            $repository = new PageRepository($context);
            $page = $repository->getPage($pageId);
            if (!$page || (int)$page['doktype'] !== PageRepository::DOKTYPE_DEFAULT
                || (int)($page['sys_language_uid'] ?? 0) !== $language->getLanguageId()
                || !$repository->isPageSuitableForLanguage($page, $context->getAspect('language'))
                || !$this->publicRootline($pageId, $context)
            ) {
                return ['status' => 'unavailable'];
            }
            $url = $site->getRouter($context)->generateUri($pageId, ['_language' => $language], '', 'url');
            // Keep ordinary suffixes byte-for-byte; they are not part of the stable page identity.
            $url = $url->withQuery($query)->withFragment($fragment);
            PeerConfiguration::origin((string)$url);
            return [
                'status' => 'resolved',
                'reference' => ['instance' => $instance, 'page' => $this->identities->forPage($pageId), 'language' => $language->getLanguageId()],
                'url' => (string)$url,
                'site' => $site->getIdentifier(),
            ];
        } catch (RouteNotFoundException|SiteNotFoundException $exception) {
            return ['status' => 'unavailable'];
        } catch (\InvalidArgumentException $exception) {
            return ['status' => $byReference ? 'unavailable' : 'unsupported'];
        }
    }

    private function publicRootline(int $pageId, Context $context): bool
    {
        $connection = $this->connections->getConnectionForTable('pages');
        $seen = [];
        $target = true;
        while ($pageId > 0) {
            if (isset($seen[$pageId]) || count($seen) >= 100) {
                return false;
            }
            $seen[$pageId] = true;
            $page = $connection->select(['*'], 'pages', ['uid' => $pageId])->fetchAssociative();
            if (!$page || $page['deleted'] || (int)$page['t3ver_wsid'] !== 0 || (int)$page['sys_language_uid'] !== 0
                || (int)$page['doktype'] === PageRepository::DOKTYPE_BE_USER_SECTION
                || (($target || $page['extendToSubpages']) && !$this->access->accessGranted('pages', $page, $context))
            ) {
                return false;
            }
            $target = false;
            $pageId = (int)$page['pid'];
        }
        return true;
    }
}
