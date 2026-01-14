<?php

namespace App\Cash\Command\Import;

use App\Cash\Message\Import\BankImportMessage;
use App\Cash\Repository\Bank\BankConnectionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'cash:bank:enqueue')]
final class BankEnqueueImportCommand extends Command
{
    public function __construct(
        private readonly BankConnectionRepository $bankConnectionRepository,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('bank', null, InputOption::VALUE_REQUIRED, 'Bank code');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bankCode = (string) $input->getOption('bank');
        if ('' === trim($bankCode)) {
            $output->writeln('<error>Bank code is required. Use --bank=alfa</error>');

            return Command::FAILURE;
        }

        $connections = $this->bankConnectionRepository->findActiveByBankCode($bankCode);

        foreach ($connections as $connection) {
            $this->bus->dispatch(new BankImportMessage(
                (string) $connection->getCompany()->getId(),
                $bankCode,
            ));
        }

        $output->writeln(sprintf('Enqueued %d bank import job(s).', count($connections)));

        return Command::SUCCESS;
    }
}
