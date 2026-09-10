<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Message\SyncOzonAccrualByDayMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ежедневная загрузка начислений Ozon через /v1/finance/accrual/by-day.
 *
 * Замена app:marketplace:ozon-daily-sync, чей источник Ozon снял 09.09.2026.
 * Команда тонкая: берёт активные Ozon-подключения и на каждый день окна шлёт
 * сообщение; вся загрузка — в SyncOzonAccrualByDayHandler.
 *
 * Окно по умолчанию 14 дней, как у легаси: Ozon правит начисления задним
 * числом, и однодневное окно эти правки терять.
 */
#[AsCommand(
    name: 'app:marketplace:ozon-accrual:daily-sync',
    description: 'Загрузка начислений Ozon (accrual by-day) за скользящее окно дней',
)]
final class OzonAccrualByDaySyncCommand extends Command
{
    private const DEFAULT_LOOKBACK_DAYS = 14;
    private const MAX_LOOKBACK_DAYS = 365;

    public function __construct(
        private readonly ActiveOzonConnectionsQuery $connectionsQuery,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days-back',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf('Глубина окна в днях, 1..%d.', self::MAX_LOOKBACK_DAYS),
            (string) self::DEFAULT_LOOKBACK_DAYS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $daysBack = (int) $input->getOption('days-back');
        if ($daysBack < 1 || $daysBack > self::MAX_LOOKBACK_DAYS) {
            $io->error(sprintf('--days-back должен быть в диапазоне 1..%d.', self::MAX_LOOKBACK_DAYS));

            return Command::FAILURE;
        }

        $connections = $this->connectionsQuery->execute();

        if ([] === $connections) {
            $io->info('Нет активных Ozon-подключений для синхронизации.');

            return Command::SUCCESS;
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $dispatched = 0;

        foreach ($connections as $row) {
            $companyId = (string) $row['company_id'];
            $connectionId = (string) $row['id'];

            for ($offset = 1; $offset <= $daysBack; ++$offset) {
                $date = $today->modify(sprintf('-%d day', $offset))->format('Y-m-d');

                $this->messageBus->dispatch(new SyncOzonAccrualByDayMessage($companyId, $connectionId, $date));
                ++$dispatched;

                $this->logger->info('Dispatched Ozon accrual by-day sync message', [
                    'company_id' => $companyId,
                    'connection_id' => $connectionId,
                    'date' => $date,
                ]);
            }
        }

        $io->success(sprintf('Отправлено %d задач на загрузку начислений Ozon за последние %d дней.', $dispatched, $daysBack));

        return Command::SUCCESS;
    }
}
