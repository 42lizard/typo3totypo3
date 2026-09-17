<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Command;

use Lizard\Typo3ToTypo3\Link\PendingStore;
use Lizard\Typo3ToTypo3\Link\RetryWorker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Bootstrap;

final class RetryCommand extends Command
{
    public function __construct(private readonly RetryWorker $worker, private readonly PendingStore $jobs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum fields per run (1–100; 45-second run budget)', '10');
        $this->addOption('list', null, InputOption::VALUE_NONE, 'List pending and attention jobs without running them');
        $this->addOption('retry', null, InputOption::VALUE_REQUIRED, 'Requeue an attention job by its source_key after correction');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $output->writeln('Limit must be between 1 and 100.');
            return self::INVALID;
        }
        Bootstrap::initializeBackendAuthentication();
        $retry = $input->getOption('retry');
        $this->jobs->adoptLegacy($limit, $retry);
        if ($input->getOption('list')) {
            $output->writeln(json_encode($this->jobs->outstanding($limit), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }
        if ($retry !== null && (!preg_match('/^[a-f0-9]{64}$/D', (string)$retry) || !$this->jobs->retry((string)$retry))) {
            $output->writeln('No idle attention job matched that key.');
            return self::FAILURE;
        }
        $result = $this->worker->run($limit, $retry);
        $output->writeln(sprintf('Processed %d fields; %d worker failures. See persisted link outcomes for retry and attention states.', $result['processed'], $result['failed']));
        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
