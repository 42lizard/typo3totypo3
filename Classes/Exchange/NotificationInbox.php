<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Exchange;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Lizard\Typo3ToTypo3\Link\DestinationStore;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use TYPO3\CMS\Core\Database\ConnectionPool;

/** Durable acceptance seam. Authorization and pairing validation precede this call. */
final class NotificationInbox
{
    public const TABLE = 'tx_typo3totypo3_notification';

    public function __construct(private readonly ConnectionPool $connections) {}

    public function accept(string $scope, array $items): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $scope) || !array_is_list($items) || !$items || count($items) > 50) {
            throw new \InvalidArgumentException('Invalid notification batch.');
        }
        foreach ($items as $item) {
            if (!is_array($item) || count($item) !== 2 || !is_array($item['reference'] ?? null)
                || count($item['reference']) !== 3 || !is_int($item['reference']['language'] ?? null)
                || !(new ManagedLink())->resolveHandlerData($item['reference'])['valid']
                || !is_int($item['revision'] ?? null) || $item['revision'] < 1 || $item['revision'] > 2147483647) {
                throw new \InvalidArgumentException('Invalid notification.');
            }
        }
        $db = $this->connections->getConnectionForTable(self::TABLE);
        if ($db !== $this->connections->getConnectionForTable(DestinationStore::TABLE)) {
            throw new \RuntimeException('Notification acceptance requires one database connection.');
        }
        $accepted = [];
        foreach ($items as $item) {
            $reference = ManagedLink::key($item['reference']);
            if (!$db->count('*', DestinationStore::TABLE, ['reference_key' => $reference])) {
                continue; // Never materialize a destination merely because a peer names it.
            }
            $key = hash('sha256', $scope . $reference);
            if (!$db->count('*', self::TABLE, ['receipt_key' => $key])) {
                try { $db->insert(self::TABLE, ['receipt_key' => $key, 'revision' => 0]); }
                catch (UniqueConstraintViolationException) {}
            }
            $accepted[] = [$key, $reference, $item['revision']];
        }
        // Commit a request's invalidations together; avoid one durable commit per destination.
        $db->transactional(function () use ($db, $accepted): void {
            foreach ($accepted as [$key, $reference, $revision]) {
                if ($db->executeStatement('UPDATE ' . self::TABLE . ' SET revision = ? WHERE receipt_key = ? AND revision < ?',
                    [$revision, $key, $revision]) !== 1) {
                    continue;
                }
                // Fence a running refresh so its completion cannot erase this newer invalidation.
                // Do not change availability or authorize a paused/denied destination.
                $db->executeStatement('UPDATE ' . DestinationStore::TABLE
                    . " SET next_refresh = 0, generation = ?"
                    . " WHERE reference_key = ? AND refresh_paused = 0 AND status <> 'denied'",
                    [bin2hex(random_bytes(16)), $reference]);
            }
        });
    }
}
