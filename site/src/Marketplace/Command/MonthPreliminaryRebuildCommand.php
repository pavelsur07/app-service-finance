<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
use App\Marketplace\Infrastructure\Query\PreliminaryClosedPeriodsQuery;
use App\Marketplace\Message\RebuildPreliminaryForPeriodMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ежедневная пересборка предварительного ОПиУ за текущий открытый месяц.
 *
 * Cron: 30 4 * * * php bin/console app:marketplace:month-preliminary-rebuild
 * (после Ozon daily sync 04:00 — данные за вчера к этому моменту уже подтянуты)
 *
 * Команда тонкая: получает все активные SELLER-подключения и для каждого
 * диспатчит RebuildPreliminaryForPeriodMessage. Вся бизнес-логика —
 * в RebuildPreliminaryForPeriodHandler / -Action.
 *
 * Системный actorUserId — фиксированный UUID '00000000-0000-0000-0000-000000000001'.
 * Поле stageXxxClosedByUserId в MarketplaceMonthClose — guid nullable БЕЗ FK,
 * поэтому несуществующий пользователь не сломает закрытие.
 */
#[AsCommand(
    name: 'app:marketplace:month-preliminary-rebuild',
    description: 'Ежедневная пересборка предварительного ОПиУ за текущий месяц для всех активных подключений',
)]
final class MonthPreliminaryRebuildCommand extends Command
{
    use LockableTrait;

    public const SYSTEM_ACTOR_USER_ID = '00000000-0000-0000-0000-000000000001';

    public function __construct(
        private readonly ActiveSellerConnectionsQuery $connectionsQuery,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly PreliminaryClosedPeriodsQuery $preliminaryPeriodsQuery,
    ) {
        parent::__construct();
    }

    /**
     * Что пересобираем этой ночью: текущий месяц по каждому активному
     * подключению плюс каждый период, закрытый предварительно.
     *
     * Текущий месяц берётся по подключениям, потому что у ни разу не закрытого
     * месяца записи закрытия ещё нет. Остальное берётся из состояния, а не из
     * календаря: загрузка by-day умеет перезаливать день окном до 365 суток, и
     * привязанный к календарному окну пересбор оставил бы документ такого месяца
     * расходиться с источником навсегда — ровно тот дефект, ради которого всё
     * это и делается.
     *
     * Дубли схлопываются: период, попавший в оба списка, пересобирается один раз.
     *
     * @param array<int, array<string, mixed>> $connections
     *
     * @return list<array{companyId: string, marketplace: string, year: int, month: int}>
     */
    private function periodsToRebuild(\DateTimeImmutable $now, array $connections): array
    {
        $periods = [];

        foreach ($connections as $row) {
            $periods[] = [
                'companyId' => (string) $row['company_id'],
                'marketplace' => (string) $row['marketplace'],
                'year' => (int) $now->format('Y'),
                'month' => (int) $now->format('n'),
            ];
        }

        foreach ($this->preliminaryPeriodsQuery->execute() as $row) {
            $periods[] = [
                'companyId' => $row['company_id'],
                'marketplace' => $row['marketplace'],
                'year' => $row['year'],
                'month' => $row['month'],
            ];
        }

        $unique = [];
        foreach ($periods as $period) {
            $key = sprintf('%s|%s|%d-%02d', $period['companyId'], $period['marketplace'], $period['year'], $period['month']);
            $unique[$key] = $period;
        }

        return array_values($unique);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->warning('Команда уже запущена другим процессом — пропускаем.');

            return Command::SUCCESS;
        }

        try {
            $connections = $this->connectionsQuery->execute();
            $periods = $this->periodsToRebuild($this->clock->now(), $connections);

            if ([] === $periods) {
                $io->info('Нет активных SELLER-подключений и предварительно закрытых периодов.');

                return Command::SUCCESS;
            }

            $dispatched = 0;
            $failed = 0;

            foreach ($periods as $period) {
                $companyId = $period['companyId'];
                $marketplace = $period['marketplace'];
                $year = $period['year'];
                $month = $period['month'];

                try {
                    $this->messageBus->dispatch(new RebuildPreliminaryForPeriodMessage(
                        companyId: $companyId,
                        marketplace: $marketplace,
                        year: $year,
                        month: $month,
                        actorUserId: self::SYSTEM_ACTOR_USER_ID,
                    ));

                    ++$dispatched;

                    $this->logger->info('[PreliminaryRebuild] Dispatched', [
                        'company_id' => $companyId,
                        'marketplace' => $marketplace,
                        'year' => $year,
                        'month' => $month,
                    ]);
                } catch (\Throwable $e) {
                    // Сбой одного диспатча не должен прерывать остальные.
                    ++$failed;

                    $this->logger->error('[PreliminaryRebuild] Dispatch failed', [
                        'company_id' => $companyId,
                        'marketplace' => $marketplace,
                        'year' => $year,
                        'month' => $month,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $io->success(sprintf(
                'Отправлено %d задач предзакрытия (ошибок: %d), периодов: %d.',
                $dispatched,
                $failed,
                count($periods),
            ));

            return Command::SUCCESS;
        } finally {
            $this->release();
        }
    }
}
