<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Message\SyncOzonReportMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ежедневная загрузка сырых данных Ozon.
 *
 * Cron: 0 4 * * * php bin/console app:marketplace:ozon-daily-sync
 *
 * Команда тонкая: получает список активных Ozon-подключений через DBAL Query,
 * для каждого отправляет Message в шину Messenger.
 * Вся логика загрузки — в SyncOzonReportHandler (Worker).
 */
#[AsCommand(
    name: 'app:marketplace:ozon-daily-sync',
    description: 'Ежедневная загрузка сырых данных Ozon за последние 3 дня',
)]
final class OzonDailySyncCommand extends Command
{
    public function __construct(
        private readonly ActiveOzonConnectionsQuery $connectionsQuery,
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
            $io->info('Нет активных Ozon-подключений для синхронизации.');

            return Command::SUCCESS;
        }

        $dispatched = 0;

        foreach ($connections as $row) {
            $companyId    = (string) $row['company_id'];
            $connectionId = (string) $row['id'];

            $this->messageBus->dispatch(
                new SyncOzonReportMessage($companyId, $connectionId),
            );

            $dispatched++;

            $this->logger->info('Dispatched Ozon sync message', [
                'company_id'    => $companyId,
                'connection_id' => $connectionId,
            ]);
        }

        $io->success(sprintf('Отправлено %d задач на загрузку данных Ozon.', $dispatched));

        return Command::SUCCESS;
    }
}
