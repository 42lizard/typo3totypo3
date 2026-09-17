<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Link;

use Lizard\Typo3ToTypo3\PeerClient;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
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
    private bool $warned = false;

    public function __construct(
        private readonly PeerConfiguration $configuration,
        private readonly PeerClient $client,
        private readonly DestinationStore $destinations,
        private readonly ConnectionPool $connections,
        private readonly TcaSchemaFactory $schemas,
        private readonly TypoLinkCodecService $codec,
        private readonly FlashMessageService $messages,
    ) {}

    public function processDatamap_beforeStart(DataHandler $handler): void
    {
        if ($handler->isOuterMostInstance()) {
            $this->deadline = null;
            $this->results = $this->outcomes = [];
            $this->warned = false;
        }
    }

    public function processDatamap_postProcessFieldArray(string $status, string $table, $id, array &$fields, DataHandler $handler): void
    {
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
        foreach ($fields as $field => $value) {
            if (!$schema->hasField($field)) {
                continue;
            }
            $fieldSchema = $schema->hasSubSchema($type) && $schema->getSubSchema($type)->hasField($field)
                ? $schema->getSubSchema($type)->getField($field) : $schema->getField($field);
            $config = $fieldSchema->getConfiguration();
            if (($config['type'] ?? '') !== 'link' || !is_string($value)) {
                continue;
            }
            $pending[$field] = ['value' => $value, 'status' => 'ordinary', 'reference_key' => ''];
            $parts = $this->codec->decode($value);
            $url = $parts['url'];
            // Existing stable links must survive peer outages without any network access.
            if (str_starts_with($url, 't3://exchange?')) {
                $pending[$field]['status'] = 'managed';
                parse_str((string)parse_url($url, PHP_URL_QUERY), $parameters);
                $reference = (new ManagedLink())->resolveHandlerData($parameters);
                if ($reference['valid']) {
                    $pending[$field]['reference_key'] = ManagedLink::key($reference);
                }
                continue;
            }
            $allowed = $config['allowedTypes'] ?? ['*'];
            if (!in_array('*', $allowed, true) && !in_array('exchange', $allowed, true)) {
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
                $candidates[$field] = ['peer' => (string)$peerId, 'url' => $url, 'parts' => $parts,
                    'max' => (int)($config['max'] ?? 0)];
                break;
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
        foreach ($candidates as $field => $candidate) {
            $result = $this->results[$candidate['peer']][$candidate['url']];
            $pending[$field]['status'] = $result['status'];
            if ($result['status'] === 'resolved') {
                $reference = $result['reference'];
                $suffix = parse_url($result['url']);
                $parameters = $reference + array_intersect_key($suffix, array_flip(['query', 'fragment']));
                $parts = $candidate['parts'];
                $parts['url'] = (new ManagedLink())->asString($parameters);
                $converted = $this->codec->encode($parts);
                if ($candidate['max'] > 0 && mb_strlen($converted) > $candidate['max']) {
                    $pending[$field]['status'] = 'too_long';
                } else {
                    $base = preg_split('/[?#]/', $result['url'], 2)[0];
                    $this->destinations->record($reference, 'resolved', $base);
                    $fields[$field] = $converted;
                    $pending[$field]['value'] = $converted;
                    $pending[$field]['reference_key'] = ManagedLink::key($reference);
                    continue;
                }
            }
            if (!$this->warned) {
                $this->messages->getMessageQueueByIdentifier()->enqueue(new FlashMessage(
                    'Some peer links could not be verified. Their original values were kept; they can be retried later.',
                    'Cross-instance links', ContextualFeedbackSeverity::WARNING, PHP_SAPI !== 'cli',
                ));
                $this->warned = true;
            }
        }
        $this->outcomes[spl_object_id($handler)][$table][$id] = $pending;
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
        $connection = $this->connections->getConnectionForTable('tx_typo3totypo3_link_outcome');
        foreach ($pending as $field => $outcome) {
            if (($record[$field] ?? null) !== $outcome['value']) {
                continue;
            }
            $key = ['source_key' => hash('sha256', $table . ':' . $uid . ':' . $field)];
            if ($outcome['status'] === 'ordinary') {
                $connection->delete('tx_typo3totypo3_link_outcome', $key);
            } else {
                $data = [
                    'table_name' => $table, 'record_uid' => $uid, 'field_name' => $field,
                    'workspace_id' => (int)($record['t3ver_wsid'] ?? 0),
                    'value_hash' => hash('sha256', $outcome['value']), 'status' => $outcome['status'],
                    'reference_key' => $outcome['reference_key'], 'checked_at' => time(),
                ];
                try {
                    $connection->insert('tx_typo3totypo3_link_outcome', $key + $data);
                } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                    $connection->update('tx_typo3totypo3_link_outcome', $data, $key);
                }
            }
        }
    }
}
