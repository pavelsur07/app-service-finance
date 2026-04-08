<?php

declare(strict_types=1);

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
 * Ежедневная загрузка сырых данных WB за предыдущий день.
 *
 * Cron: 0 3 * * * php bin/console app:marketplace:wb-daily-sync
 *
 * Команда тонкая: получает список активных WB-подключений через DBAL Query,
 * для каждого отправляет Message в шину Messenger.
 * Вся логика загрузки — в SyncWbReportHandler (Worker).
 * Обработка данных — вручную через UI или отдельной командой.
 */
#[AsCommand(
    name: 'app:marketplace:wb-daily-sync',
    description: 'Ежедневная загрузка сырых данных WB за предыдущий день',
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
            $companyId    = (string) $row['company_id'];
            $connectionId = (string) $row['id'];

            $this->messageBus->dispatch(
                new SyncWbReportMessage($companyId, $connectionId),
            );

            $dispatched++;

            $this->logger->info('Dispatched WB sync message', [
                'company_id'    => $companyId,
                'connection_id' => $connectionId,
            ]);
        }

        $io->success(sprintf('Отправлено %d задач на загрузку данных WB.', $dispatched));

        return Command::SUCCESS;
    }
}
