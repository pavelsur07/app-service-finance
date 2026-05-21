<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Infrastructure\Query\ActiveWbConnectionsQuery;
use App\Marketplace\Message\SyncWbFinancialReportDayMessage;
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
 * Вся логика загрузки — в SyncWbFinancialReportDayHandler (Worker).
 * Загрузка выполняется в SyncWbFinancialReportDayHandler; после успешной загрузки raw document handler отправляет ProcessDayReportMessage в async_pipeline.
 */
#[AsCommand(
    name: 'app:marketplace:wb-daily-sync',
    description: 'Ежедневная загрузка сырых данных WB за предыдущий день',
)]
final class MarketplaceNightlySyncCommand extends Command
{
    public function __construct(
        private readonly ActiveWbConnectionsQuery $connectionsQuery,
        private readonly WbFinancialReportPeriodResolver $periodResolver,
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

        $businessDate = $this->periodResolver->yesterday()->format('Y-m-d');
        $dispatched = 0;

        foreach ($connections as $row) {
            $companyId    = (string) $row['company_id'];
            $connectionId = (string) $row['id'];

            $this->messageBus->dispatch(
                new SyncWbFinancialReportDayMessage(
                    $companyId,
                    $connectionId,
                    $businessDate,
                    FinancialReportSyncMode::DAILY->value,
                    false,
                ),
            );

            $dispatched++;

            $this->logger->info('Dispatched WB sync message', [
                'company_id'    => $companyId,
                'connection_id' => $connectionId,
                'business_date' => $businessDate,
                'mode'          => FinancialReportSyncMode::DAILY->value,
            ]);
        }

        $io->success(sprintf('Отправлено %d задач на загрузку данных WB.', $dispatched));

        return Command::SUCCESS;
    }
}
