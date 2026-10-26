<?php

declare(strict_types=1);

namespace App\BankSync\Command;

use App\BankSync\Enum\BankCode;
use App\BankSync\Message\SyncBankConnectionMessage;
use App\BankSync\Repository\BankConnectionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'bank-sync:enqueue', description: 'Поставить загрузку выписки по всем активным подключениям банка')]
final class EnqueueBankSyncCommand extends Command
{
    public function __construct(
        private readonly BankConnectionRepository $connections,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('bank', null, InputOption::VALUE_REQUIRED, 'Код банка: '.implode(', ', array_column(BankCode::cases(), 'value')));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bankCode = BankCode::tryFrom(trim((string) $input->getOption('bank')));
        if (null === $bankCode) {
            $output->writeln('<error>Unknown or missing bank code. Use --bank=alfa</error>');

            return Command::INVALID;
        }

        $connections = $this->connections->findActiveByBankCode($bankCode);
        foreach ($connections as $connection) {
            $this->bus->dispatch(new SyncBankConnectionMessage($connection->getCompanyId(), $connection->getId()));
        }

        $output->writeln(sprintf('Enqueued %d bank sync job(s).', \count($connections)));

        return Command::SUCCESS;
    }
}
