<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Exchange;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/** Persist only local work; request handlers never report usage over the network. */
final class SourceUsageHook
{
    public function __construct(private readonly SourceUsage $sources, private readonly ExchangeConfiguration $configuration, private readonly DestinationChanges $changes) {}

    public function processDatamap_afterDatabaseOperations(string $status, string $table, $id, array $fields, DataHandler $handler): void
    {
        if (!$this->active()) { return; }
        $this->sources->changed($table, (int)($handler->substNEWwithIDs[$id] ?? $id));
        if ($table === 'pages') { $this->changes->requestRecheck((int)($handler->substNEWwithIDs[$id] ?? $id)); }
    }

    public function processCmdmap_postProcess(string $command, string $table, $id, $value, DataHandler $handler): void
    {
        if (!$this->active()) { return; }
        $this->sources->changed($table, (int)$id);
        if ($table === 'pages') { $this->changes->requestRecheck((int)$id); }
        if (is_array($value) && isset($value['swapWith'])) {
            $this->sources->changed($table, (int)$value['swapWith']);
        }
    }

    public function processCmdmap_afterFinish(DataHandler $handler): void
    {
        if (!$this->active()) { return; }
        foreach ($handler->copyMappingArray_merged as $table => $mapping) {
            foreach ($mapping as $newId) { $this->sources->changed($table, (int)$newId); }
        }
        // Covers recursive delete/restore and workspace publication paths as a resumable pass.
        $this->sources->requestReconciliation();
        $this->changes->requestRecheck();
    }
    private function active(): bool
    {
        try { $this->configuration->load(); return true; }
        catch (\RuntimeException) { return false; }
    }

}
