<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Backend;

use Lizard\Typo3ToTypo3\Configuration\ConnectionStore;
use Lizard\Typo3ToTypo3\PeerClient;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\FormProtection\BackendFormProtection;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;

final class ConnectionsController
{
    public const MODULE = 'exchange_connections';

    public function __construct(
        private readonly ConnectionStore $store,
        private readonly PeerClient $client,
        private readonly ModuleTemplateFactory $templates,
        private readonly UriBuilder $uris,
        private readonly FormProtectionFactory $forms,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        if (empty($GLOBALS['BE_USER']->user['uid']) || !$GLOBALS['BE_USER']->isAdmin()) {
            return new HtmlResponse(Labels::text('error.denied'), 403);
        }
        $form = $this->forms->createFromRequest($request);
        if (!$form instanceof BackendFormProtection) {
            return new HtmlResponse(Labels::text('error.session'), 403);
        }
        if (!in_array($request->getMethod(), ['GET', 'POST'], true)) {
            return (new HtmlResponse('', 405))->withHeader('Allow', 'GET, POST');
        }
        $message = '';
        $token = '';
        $status = 200;
        try {
            $state = $this->store->read();
        } catch (\Throwable) {
            return $this->render($request, ['setupRequired' => true], 503);
        }
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            if (!is_array($body) || !is_string($body['csrf'] ?? null) || !$form->validateToken($body['csrf'], 'exchange-connections')) {
                return new HtmlResponse(Labels::text('error.token'), 403);
            }
            try {
                // A stale form must never replace a newer credential or connection edit.
                if (self::input($body, 'revision') !== $state['revision']) {
                    throw new \RuntimeException('Stale form.');
                }
                $action = self::input($body, 'action');
                $config = $state['config'];
                if ($action === 'import') {
                    $this->store->import();
                } elseif ($action === 'test') {
                    $name = self::input($body, 'name');
                    $peer = $config['outgoing'][$name] ?? null;
                    if (!$peer) { throw new \InvalidArgumentException('Unknown peer.'); }
                    $result = $this->client->resolve($name, [rtrim($peer['origins'][0], '/') . '/'])[0];
                    $message = Labels::text($result['status'] === 'resolved' ? 'connections.testOk' : 'connections.testFailed');
                } else {
                    switch ($action) {
                        case 'settings':
                            $config['instance'] = $state['revision'] === '' ? self::input($body, 'instance') : $config['instance'];
                            $config['enabled'] = self::input($body, 'enabled') === '1';
                            $config['publicAliases'] = [];
                            foreach (self::lines(self::input($body, 'aliases')) as $line) {
                                $pair = explode('=', $line, 2);
                                if (count($pair) !== 2) { throw new \InvalidArgumentException('Invalid alias.'); }
                                $config['publicAliases'][trim($pair[0])] = trim($pair[1]);
                            }
                            break;
                        case 'outgoing':
                            $name = self::input($body, 'name');
                            $secret = self::input($body, 'token');
                            if ($secret === '' && self::input($body, 'rotate') !== '1') {
                                $secret = $config['outgoing'][$name]['token'] ?? '';
                            }
                            if ($secret === '') { $secret = $token = bin2hex(random_bytes(32)); }
                            $config['outgoing'][$name] = ['enabled' => self::input($body, 'enabled') === '1',
                                'instance' => self::input($body, 'instance'), 'endpoint' => self::input($body, 'endpoint'),
                                'origins' => self::lines(self::input($body, 'origins')), 'token' => $secret];
                            break;
                        case 'incoming':
                            $instance = self::input($body, 'instance');
                            $secret = self::input($body, 'token');
                            if ($secret !== '' && !preg_match('/^[a-f0-9]{64}$/D', $secret)) { throw new \InvalidArgumentException('Invalid token.'); }
                            $config['incoming'][$instance] = ['enabled' => self::input($body, 'enabled') === '1',
                                'tokenHash' => $secret === '' ? ($config['incoming'][$instance]['tokenHash'] ?? '') : hash('sha256', $secret),
                                'sites' => self::lines(self::input($body, 'sites')), 'requestsPerMinute' => (int)self::input($body, 'rate')];
                            break;
                        case 'removeOutgoing':
                            unset($config['outgoing'][self::input($body, 'name')]);
                            break;
                        case 'removeIncoming':
                            unset($config['incoming'][self::input($body, 'instance')]);
                            break;
                        default:
                            throw new \InvalidArgumentException('Invalid action.');
                    }
                    $this->store->save($config, $state['revision']);
                }
                $message = $message ?: Labels::text('connections.saved');
                $state = $this->store->read();
            } catch (\Throwable) {
                $token = '';
                $message = Labels::text('connections.failed');
                $status = 400;
            }
        }
        $config = $state['config'];
        $outgoing = [];
        foreach ($config['outgoing'] ?? [] as $name => $peer) {
            // Explicit allowlist: no saved token or hash is ever assigned to the view.
            $outgoing[] = ['name' => $name, 'instance' => $peer['instance'], 'endpoint' => $peer['endpoint'],
                'origins' => implode("\n", $peer['origins']), 'enabled' => $peer['enabled'], 'existing' => true];
        }
        $incoming = [];
        foreach ($config['incoming'] ?? [] as $instance => $grant) {
            $incoming[] = ['instance' => $instance, 'sites' => implode("\n", $grant['sites']),
                'rate' => $grant['requestsPerMinute'], 'enabled' => $grant['enabled'], 'existing' => true];
        }
        $outgoing[] = ['enabled' => true];
        $incoming[] = ['enabled' => true, 'rate' => 120];
        $aliases = [];
        foreach ($config['publicAliases'] ?? [] as $old => $new) { $aliases[] = $old . '=' . $new; }
        return $this->render($request, ['revision' => $state['revision'], 'initialized' => $state['revision'] !== '',
            'instance' => $config['instance'] ?: PeerConfiguration::uuid(), 'enabled' => $config['enabled'],
            'aliases' => implode("\n", $aliases), 'outgoing' => $outgoing, 'incoming' => $incoming,
            'message' => $message, 'newToken' => $token, 'csrf' => $form->generateToken('exchange-connections')], $status);
    }

    private function render(ServerRequestInterface $request, array $data, int $status): ResponseInterface
    {
        $view = $this->templates->create($request);
        $view->setTitle(Labels::text('connections.title'));
        $view->assignMultiple($data + ['url' => (string)$this->uris->buildUriFromRoute(self::MODULE)]);
        return $view->renderResponse('Connections/Index')->withStatus($status)
            ->withHeader('Cache-Control', 'no-store, private')->withHeader('Referrer-Policy', 'no-referrer');
    }

    private static function input(array $body, string $key): string
    {
        $value = $body[$key] ?? '';
        if (!is_string($value) || strlen($value) > 16384) { throw new \InvalidArgumentException('Invalid input.'); }
        return trim($value);
    }

    private static function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value)), static fn($line) => $line !== ''));
    }
}
