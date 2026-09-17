<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Command;

use Lizard\Typo3ToTypo3\Link\RefreshWorker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RefreshCommand extends Command
{
    public function __construct(private readonly RefreshWorker $worker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum destinations per run (1–10000; 45-second budget)', '5000');
        $this->addOption('resume', null, InputOption::VALUE_REQUIRED, 'Recheck a denied peer after administrator correction');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $output->writeln('Limit must be between 1 and 10000.');
            return self::INVALID;
        }
        try {
            $result = $this->worker->run($limit, $input->getOption('resume'));
        } catch (\Throwable) {
            $output->writeln('Refresh could not run; check peer configuration and database availability.');
            return self::FAILURE;
        }
        $output->writeln(sprintf('Processed %d destinations; %d failed peer batches. See destination refresh states for details.', $result['processed'], $result['failed']));
        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
