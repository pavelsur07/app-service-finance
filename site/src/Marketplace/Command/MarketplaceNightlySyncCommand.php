<?php

namespace App\Marketplace\Command;

use App\Marketplace\Infrastructure\Query\ActiveWbConnectionsQuery;
use App\Marketplace\Message\SyncWbReportMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ночная синхронизация отчётов WB по реализации.
 *
 * Cron: 0 3 * * * php bin/console app:marketplace:nightly-sync
 *
 * Команда тонкая: получает список активных подключений через DBAL Query,
 * для каждого отправляет Message в очередь Messenger.
 * Вся логика обработки — в SyncWbReportHandler (Worker).
 */
#[AsCommand(
    name: 'app:marketplace:nightly-sync',
    description: 'Ночная синхронизация отчётов WB для маркетплейсов',
)]
final class MarketplaceNightlySyncCommand extends Command
{
    public function __construct(
        private readonly ActiveWbConnectionsQuery $connectionsQuery,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $connections = $this->connectionsQuery->execute();

        if (empty($connections)) {
            $io->info('Нет активных WB-подключений для синхронизации.');
            return Command::SUCCESS;
        }

        $dispatched = 0;

        foreach ($connections as $row) {
            $companyId = (string) $row['company_id'];
            $connectionId = (string) $row['id'];

            $this->messageBus->dispatch(
                new SyncWbReportMessage($companyId, $connectionId)
            );

            $dispatched++;

            $this->logger->info('Dispatched WB sync message', [
                'company_id' => $companyId,
                'connection_id' => $connectionId,
            ]);
        }

        $io->success(sprintf('Отправлено %d задач на синхронизацию WB.', $dispatched));

        return Command::SUCCESS;
    }
}
