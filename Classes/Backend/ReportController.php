<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\FormProtection\BackendFormProtection;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;

final class ReportController
{
    public function __construct(
        private readonly LinkReport $report,
        private readonly ModuleTemplateFactory $templates,
        private readonly UriBuilder $uris,
        private readonly FormProtectionFactory $forms,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->report->allowed()) {
            return new HtmlResponse(Labels::text('error.denied'), 403);
        }
        $form = $this->forms->createFromRequest($request);
        if (!$form instanceof BackendFormProtection) {
            return new HtmlResponse(Labels::text('error.session'), 403);
        }
        $url = (string)$this->uris->buildUriFromRoute(LinkReport::MODULE);
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            if (!is_array($body) || !is_string($body['csrf'] ?? null)
                || !$form->validateToken($body['csrf'], 'exchange-report')) {
                return new HtmlResponse(Labels::text('error.token'), 403);
            }
            $ok = match ($body['action'] ?? null) {
                'retry' => is_string($body['key'] ?? null) && is_string($body['generation'] ?? null)
                    && $this->report->retry($body['key'], $body['generation']),
                'resume' => is_string($body['peer'] ?? null) && $this->report->resume($body['peer']),
                default => false,
            };
            return $ok ? new RedirectResponse($url, 303) : new HtmlResponse(Labels::text('error.action'), 403);
        }
        if ($request->getMethod() !== 'GET') {
            return (new HtmlResponse(Labels::text('error.method'), 405))->withHeader('Allow', 'GET, POST');
        }
        $offset = filter_var($request->getQueryParams()['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]) ?: 0;
        $page = $this->report->page($offset);
        foreach ($page['rows'] as &$row) {
            $row['stateLabel'] = Labels::text('state.' . (in_array($row['status'], ['pending', 'stale', 'unavailable', 'denied', 'expired', 'unsupported', 'too_long', 'disabled', 'database', 'persistence', 'permissions', 'missing'], true) ? $row['status'] : 'invalid'));
            $row['edit'] = (string)$this->uris->buildUriFromRoute('record_edit', ['edit' => [$row['table'] => [$row['uid'] => 'edit']], 'returnUrl' => $url]);
        }
        unset($row);
        $connectionRows = $this->report->connections();
        foreach ($connectionRows as &$connection) {
            $connection['title'] = Labels::text('connection.title', [$connection['peer']]);
        }
        unset($connection);
        $view = $this->templates->create($request);
        $view->setTitle(Labels::text('module.title'));
        $view->assignMultiple(['intro' => Labels::text('report.intro', [$GLOBALS['BE_USER']->workspace]), 'rows' => $page['rows'], 'connections' => $connectionRows,
            'workspace' => $GLOBALS['BE_USER']->workspace, 'url' => $url, 'csrf' => $form->generateToken('exchange-report'),
            'previous' => $offset ? (string)$this->uris->buildUriFromRoute(LinkReport::MODULE, ['offset' => max(0, $offset - 100)]) : '',
            'next' => $page['more'] ? (string)$this->uris->buildUriFromRoute(LinkReport::MODULE, ['offset' => $offset + 100]) : '']);
        return $view->renderResponse('Report/Index')->withHeader('Cache-Control', 'no-store');
    }
}
