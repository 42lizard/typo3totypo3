<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Command;

use Lizard\Typo3ToTypo3\PeerClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ResolveCommand extends Command
{
    public function __construct(private readonly PeerClient $client)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('peer', InputArgument::REQUIRED, 'Configured outgoing peer name');
        $this->addArgument('url', InputArgument::REQUIRED, 'Readable page URL');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->client->resolve((string)$input->getArgument('peer'), [(string)$input->getArgument('url')])[0];
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return $result['status'] === 'resolved' ? self::SUCCESS : self::FAILURE;
        } catch (\RuntimeException|\InvalidArgumentException $exception) {
            $output->writeln($exception->getMessage(), OutputInterface::OUTPUT_RAW);
            return self::FAILURE;
        }
    }
}
