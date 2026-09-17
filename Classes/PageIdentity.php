<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class PageIdentity
{
    private const TABLE = 'tx_typo3totypo3_identity';

    public function __construct(private readonly ConnectionPool $connections) {}

    public function forPage(int $pageId): string
    {
        $connection = $this->connections->getConnectionForTable(self::TABLE);
        $uuid = $connection->select(['uuid'], self::TABLE, ['page_uid' => $pageId])->fetchOne();
        if (is_string($uuid)) {
            return $uuid;
        }
        $uuid = PeerConfiguration::uuid();
        try {
            $connection->insert(self::TABLE, ['page_uid' => $pageId, 'uuid' => $uuid]);
        } catch (UniqueConstraintViolationException $exception) {
            // Another request may have assigned this page's identity concurrently.
            $existing = $connection->select(['uuid'], self::TABLE, ['page_uid' => $pageId])->fetchOne();
            if (!is_string($existing)) {
                throw $exception;
            }
            return $existing;
        }
        return $uuid;
    }

    public function findPage(string $uuid): ?int
    {
        $value = $this->connections->getConnectionForTable(self::TABLE)
            ->select(['page_uid'], self::TABLE, ['uuid' => $uuid])->fetchOne();
        return $value === false ? null : (int)$value;
    }
}
