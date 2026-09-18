<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Exchange;

use Lizard\Typo3ToTypo3\PageResolver;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class UsageReceiver
{
    public function __construct(
        private readonly UsageRegistry $registry,
        private readonly PageResolver $resolver,
        private readonly UsageAccess $access,
        private readonly ConnectionPool $connections,
    ) {}

    public function receive(array $config, array $channel, array $payload): array
    {
        $scope = ExchangeConfiguration::scope($config, $channel);
        $grant = ExchangeConfiguration::fingerprint($channel);
        $operation = $payload['operation'] ?? null;
        if (!in_array($operation, ['report', 'stage', 'complete'], true)) {
            throw new \InvalidArgumentException('Invalid usage operation.');
        }
        if ($operation !== 'report' && (!is_string($payload['snapshot'] ?? null)
            || !PeerConfiguration::isUuid($payload['snapshot']) || !is_int($payload['revision'] ?? null)
            || $payload['revision'] < 1 || $payload['revision'] > 2147483647)) {
            throw new \InvalidArgumentException('Invalid snapshot.');
        }
        if ($operation === 'complete') {
            if (count($payload) !== 6 || !is_int($payload['count'] ?? null) || !is_string($payload['digest'] ?? null)) {
                throw new \InvalidArgumentException('Invalid snapshot completion.');
            }
            $this->registry->rememberScope($scope, $channel);
            $this->registry->complete($scope, $payload['snapshot'], $payload['revision'], $payload['count'], $payload['digest'], $grant);
            return ['complete' => true, 'unaccepted' => []];
        }
        $items = $payload['items'] ?? null;
        if (count($payload) !== ($operation === 'report' ? 3 : 5) || !is_array($items) || !array_is_list($items) || count($items) > 50) {
            throw new \InvalidArgumentException('Invalid usage batch.');
        }
        // Validate the entire wire payload before resolving or persisting any part of it.
        foreach ($items as $item) {
            if (!is_array($item) || count($item) !== ($operation === 'report' ? 4 : 2)
                || !is_string($item['page'] ?? null) || !PeerConfiguration::isUuid($item['page'])
                || !is_int($item['language'] ?? null) || $item['language'] < 0 || $item['language'] > 2147483647
                || ($operation === 'report' && (!is_bool($item['present'] ?? null) || !is_int($item['revision'] ?? null)
                    || $item['revision'] < 1 || $item['revision'] > 2147483647))) {
                throw new \InvalidArgumentException('Invalid usage item.');
            }
        }
        $accepted = [];
        $unaccepted = [];
        foreach ($items as $index => $item) {
            $site = $this->authorizedSite($scope, $item, $config, $channel);
            if ($site === null) {
                $unaccepted[] = $index;
            } else {
                $accepted[] = $item + ['site' => $site];
            }
        }
        $this->registry->rememberScope($scope, $channel);
        if ($operation === 'report') {
            $this->registry->report($scope, $accepted);
        } elseif ($unaccepted === []) {
            // A partially authorized chunk cannot become a complete authoritative snapshot.
            $this->registry->stage($scope, $payload['snapshot'], $payload['revision'], $accepted, $grant);
        }
        return ['complete' => $unaccepted === [], 'unaccepted' => $unaccepted];
    }

    private function authorizedSite(string $scope, array $item, array $config, array $channel): ?string
    {
        $known = $this->connections->getConnectionForTable(UsageRegistry::TABLE)
            ->select(['*'], UsageRegistry::TABLE, ['scope_key' => $scope, 'page_uuid' => $item['page'], 'language_id' => $item['language']])
            ->fetchAssociative();
        if (($item['present'] ?? true) === false) {
            // Removing this caller's own declaration discloses no destination information.
            return $known['site_identifier'] ?? '__unregistered__';
        }
        $result = $this->resolver->resolve(['instance' => $config['instance'], 'page' => $item['page'], 'language' => $item['language']],
            true, $channel['sites'], $config['instance']);
        if ($result['status'] === 'resolved') {
            return $result['site'];
        }
        // A historical reference alone does not authorize first registration of an unavailable page.
        if (!$known || !(int)$known['present']) {
            return null;
        }
        return $this->access->site($known, $channel);
    }
}
