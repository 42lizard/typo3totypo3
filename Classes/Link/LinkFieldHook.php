<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Link;

use Lizard\Typo3ToTypo3\PeerClient;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;

/** Runs only on fields which DataHandler has already authorized and validated. */
final class LinkFieldHook
{
    private ?float $deadline = null;
    private array $results = [];
    private array $outcomes = [];
    private array $warned = [];
    private bool $background = false;
    private bool $applying = false;

    public function __construct(
        private readonly PendingStore $jobs,
        private readonly PeerConfiguration $configuration,
        private readonly PeerClient $client,
        private readonly DestinationStore $destinations,
        private readonly TcaSchemaFactory $schemas,
        private readonly TypoLinkCodecService $codec,
        private readonly FlashMessageService $messages,
        private readonly \TYPO3\CMS\Core\Configuration\Richtext $richtext,
    ) {}

    public function processDatamap_beforeStart(DataHandler $handler): void
    {
        if ($handler->isOuterMostInstance()) {
            $this->deadline = null;
            $this->results = $this->outcomes = [];
            $this->warned = [];
        }
    }

    public function processDatamap_postProcessFieldArray(string $status, string $table, $id, array &$fields, DataHandler $handler): void
    {
        if ($this->applying) {
            return;
        }
        try {
            $peers = $this->configuration->load()['outgoing'] ?? [];
        } catch (\RuntimeException|\JsonException) {
            return;
        }
        $schema = $this->schemas->get($table);
        $record = is_numeric($id) ? (BackendUtility::getRecord($table, (int)$id, '*', '', false) ?? []) : [];
        $record = array_replace($record, $fields);
        $type = BackendUtility::getTCAtypeValue($table, $record);
        $candidates = [];
        $pending = [];
        $rteAnchors = [];
        $limits = [];
        foreach ($fields as $field => $value) {
            if (!$schema->hasField($field) || !is_string($value)) {
                continue;
            }
            $fieldSchema = $schema->hasSubSchema($type) && $schema->getSubSchema($type)->hasField($field)
                ? $schema->getSubSchema($type)->getField($field) : $schema->getField($field);
            $config = $fieldSchema->getConfiguration();
            $isRte = ($config['type'] ?? '') === 'text' && ($config['enableRichtext'] ?? false);
            if (($config['type'] ?? '') !== 'link' && !$isRte) {
                continue;
            }
            $pending[$field] = ['value' => $value, 'status' => 'ordinary', 'reference_key' => ''];
            $limits[$field] = (int)($config['max'] ?? 0);
            $allowed = $config['allowedTypes'] ?? ['*'];
            if ($isRte) {
                $rteConfig = $this->richtext->getConfiguration($table, $field, (int)($record['pid'] ?? 0), $type, $config);
                $allowed = isset($rteConfig['allowedTypes'])
                    ? array_map('trim', explode(',', $rteConfig['allowedTypes'])) : ['*'];
                if (!isset($rteConfig['allowedTypes']) && array_intersect(['url', 'exchange'], array_map('trim', explode(',', $rteConfig['blindLinkOptions'] ?? '')))) {
                    $allowed = [];
                }
                $rteAnchors[$field] = RteLinks::anchors($value);
                $links = $rteAnchors[$field];
            } else {
                $links = [$this->codec->decode($value)];
            }
            foreach ($links as $index => $parts) {
                $url = $parts['url'];
                // Existing stable links survive outages without any network access.
                if (str_starts_with($url, 't3://exchange?')) {
                    $pending[$field]['status'] = 'managed';
                    parse_str((string)parse_url($url, PHP_URL_QUERY), $parameters);
                    $reference = (new ManagedLink())->resolveHandlerData($parameters);
                    if ($reference['valid'] && !$isRte) {
                        $pending[$field]['reference_key'] = ManagedLink::key($reference);
                    }
                    continue;
                }
                if (!in_array('*', $allowed, true) && (!in_array('exchange', $allowed, true) || !in_array('url', $allowed, true))) {
                    continue;
                }
                foreach ($peers as $peerId => $peer) {
                    try {
                        if (($peer['enabled'] ?? false) !== true || !PeerConfiguration::allowsUrl($url, $peer['origins'] ?? [])) {
                            continue;
                        }
                    } catch (\InvalidArgumentException) {
                        continue;
                    }
                    $candidates[] = ['field' => $field, 'index' => $index, 'rte' => $isRte,
                        'peer' => (string)$peerId, 'url' => $url, 'parts' => $parts];
                    break;
                }
            }
        }
        $groups = [];
        foreach ($candidates as $candidate) {
            $peer = $candidate['peer'];
            $url = $candidate['url'];
            if (!isset($this->results[$peer][$url])) {
                $groups[$peer][$url] = $url;
            }
        }
        foreach ($groups as $peer => $urls) {
            // Bound both count and encoded request size. A valid URL may contain many escaped bytes.
            $batch = [];
            foreach ($urls as $url) {
                if ($batch && (count($batch) >= 50 || strlen(json_encode(['urls' => [...$batch, $url]], JSON_THROW_ON_ERROR)) > 65536)) {
                    $this->resolveBatch((string)$peer, $batch);
                    $batch = [];
                }
                $batch[] = $url;
            }
            if ($batch) {
                $this->resolveBatch((string)$peer, $batch);
            }
        }
        $replacements = [];
        $verified = [];
        foreach ($candidates as $candidate) {
            $field = $candidate['field'];
            $result = $this->results[$candidate['peer']][$candidate['url']];
            if ($result['status'] !== 'resolved') {
                // Keep a failure outcome even when other anchors in this field resolved.
                $priority = ['pending' => 4, 'denied' => 5, 'unavailable' => 3, 'unsupported' => 2];
                if (($priority[$result['status']] ?? 0) > ($priority[$pending[$field]['status']] ?? 0)) {
                    $pending[$field]['status'] = $result['status'];
                }
                $this->warn($table, $field);
                continue;
            }
            $reference = $result['reference'];
            $suffix = parse_url($result['url']);
            $parameters = $reference + array_intersect_key($suffix, array_flip(['query', 'fragment']));
            $managed = (new ManagedLink())->asString($parameters);
            if ($candidate['rte']) {
                $replacements[$field][$candidate['index']] = $managed;
            } else {
                $parts = $candidate['parts'];
                $parts['url'] = $managed;
                $fields[$field] = $this->codec->encode($parts);
                $pending[$field]['reference_key'] = ManagedLink::key($reference);
            }
            $verified[$field][] = $result;
            if (in_array($pending[$field]['status'], ['ordinary', 'managed'], true)) {
                $pending[$field]['status'] = 'resolved';
            }
        }
        foreach ($verified as $field => $results) {
            if (isset($replacements[$field])) {
                $fields[$field] = RteLinks::replace($fields[$field], $rteAnchors[$field], $replacements[$field]);
            }
            if ($limits[$field] > 0 && mb_strlen($fields[$field]) > $limits[$field]) {
                $fields[$field] = $pending[$field]['value'];
                $pending[$field]['status'] = 'too_long';
                $pending[$field]['reference_key'] = '';
                $this->warn($table, $field);
                continue;
            }
            foreach ($results as $result) {
                $this->destinations->record($result['reference'], 'resolved', preg_split('/[?#]/', $result['url'], 2)[0]);
            }
            $pending[$field]['value'] = $fields[$field];
        }
        $this->outcomes[spl_object_id($handler)][$table][$id] = $pending;
    }

    private function warn(string $table, string $field): void
    {
        if ($this->background) {
            return;
        }
        $key = $table . '.' . $field;
        if (!isset($this->warned[$key])) {
            $this->messages->getMessageQueueByIdentifier()->enqueue(new FlashMessage(
                'Some peer links in ' . $key . ' could not be verified. Their original values were kept. Temporary failures are queued for automatic retry.',
                'Cross-instance links', ContextualFeedbackSeverity::WARNING, PHP_SAPI !== 'cli',
            ));
            $this->warned[$key] = true;
        }
    }

    private function resolveBatch(string $peer, array $urls): void
    {
        $now = hrtime(true) / 1e9;
        $this->deadline ??= $now + 3.0;
        $remaining = $this->deadline - $now;
        try {
            if ($remaining <= 0.001) {
                throw new \RuntimeException('Save-time resolution budget exhausted.');
            }
            $results = $this->client->resolve($peer, $urls, min(3.0, $remaining));
        } catch (\RuntimeException|\InvalidArgumentException|\JsonException $exception) {
            $results = array_fill(0, count($urls), ['status' => in_array($exception->getCode(), [401, 403], true) ? 'denied' : 'pending']);
        }
        foreach ($urls as $index => $url) {
            $this->results[$peer][$url] = $results[$index];
        }
    }

    public function processDatamap_afterDatabaseOperations(string $status, string $table, $id, array $fields, DataHandler $handler): void
    {
        $pending = $this->outcomes[spl_object_id($handler)][$table][$id] ?? [];
        unset($this->outcomes[spl_object_id($handler)][$table][$id]);
        $uid = (int)($handler->substNEWwithIDs[$id] ?? $id);
        if (!$pending || $uid <= 0) {
            return;
        }
        $record = BackendUtility::getRecord($table, $uid, '*', '', false);
        if (!$record) {
            return;
        }
        foreach ($pending as $field => $outcome) {
            if (($record[$field] ?? null) === $outcome['value']) {
                $this->jobs->record($table, $uid, $field, $record, $outcome);
            }
        }
    }

    /** Resolve outside the worker's write transaction, using exactly the editor conversion rules. */
    public function prepare(string $table, int $uid, string $field, string $value): array
    {
        $handler = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DataHandler::class);
        $this->deadline = null;
        $this->results = [];
        $this->background = true;
        try {
            $fields = [$field => $value];
            $this->processDatamap_postProcessFieldArray('update', $table, $uid, $fields, $handler);
            $outcome = $this->outcomes[spl_object_id($handler)][$table][$uid][$field] ?? ['status' => 'disabled'];
            return ['value' => $fields[$field], 'status' => $outcome['status'], 'reference_key' => $outcome['reference_key'] ?? ''];
        } finally {
            unset($this->outcomes[spl_object_id($handler)]);
            $this->background = false;
        }
    }

    /** Already verified replacements must not recurse into networking or job creation. */
    public function applyPrepared(callable $write): void
    {
        $this->applying = true;
        try {
            $write();
        } finally {
            $this->applying = false;
        }
    }
}
