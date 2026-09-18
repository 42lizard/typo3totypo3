<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Command;

use Lizard\Typo3ToTypo3\Exchange\DestinationChanges;
use Lizard\Typo3ToTypo3\Exchange\ExchangeWorker;
use Lizard\Typo3ToTypo3\Exchange\SourceUsage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SyncCommand extends Command
{
    public function __construct(private readonly ExchangeWorker $worker, private readonly SourceUsage $sources, private readonly DestinationChanges $changes) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum work items per run; 45-second start-work budget', '10000');
        $this->addOption('recheck-capabilities', null, InputOption::VALUE_NONE, 'Probe explicitly enabled capabilities again');
        $this->addOption('reconcile', null, InputOption::VALUE_NONE, 'Request a complete source reconciliation');
        $this->addOption('recheck-destinations', null, InputOption::VALUE_NONE, 'Recheck used destinations after routing or site configuration changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if ($limit === false) { $output->writeln('Limit must be between 1 and 100000.'); return self::INVALID; }
        try {
            if ($input->getOption('reconcile')) { $this->sources->requestReconciliation(); }
            if ($input->getOption('recheck-destinations')) { $this->changes->requestRecheck(); }
            $result = $this->worker->run($limit, $input->getOption('recheck-capabilities'));
        } catch (\Throwable) {
            $output->writeln('Exchange could not run; check activation, configuration and database availability.');
            return self::FAILURE;
        }
        $output->writeln(sprintf('Accepted deliveries: %d; checked destinations: %d; incomplete reports: %d; failed batches: %d.',
            $result['accepted'], $result['checked'], $result['deferred'], $result['failed']));
        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
