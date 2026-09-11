<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
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

    /** Сколько первых дней месяца пересобирать ещё и предыдущий месяц. */
    private const PREVIOUS_MONTH_TAIL_DAYS = 4;

    public function __construct(
        private readonly ActiveSellerConnectionsQuery $connectionsQuery,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    /**
     * Периоды, которые пересобираем этой ночью.
     *
     * Текущий месяц — всегда. Предыдущий — в первые дни месяца, пока окно
     * загрузки by-day ещё достаёт до него: загрузка снимает привязку к
     * предварительному ОПиУ и заменяет строки, а пересобрать документ некому,
     * если пересбор ходит только по текущему месяцу. Так документ прошлого
     * месяца остался бы расходиться с источником до самого финального закрытия.
     *
     * Хвост взят с запасом относительно окна загрузки (2 дня), чтобы не зависеть
     * от точного совпадения двух констант в разных командах. Лишние прогоны
     * безвредны: `RebuildPreliminaryForPeriodAction` пропускает этап, закрытый
     * окончательно.
     *
     * @return list<array{int, int}>
     */
    private function periodsToRebuild(\DateTimeImmutable $now): array
    {
        $periods = [[(int) $now->format('Y'), (int) $now->format('n')]];

        if ((int) $now->format('j') <= self::PREVIOUS_MONTH_TAIL_DAYS) {
            $previous = $now->modify('first day of previous month');
            $periods[] = [(int) $previous->format('Y'), (int) $previous->format('n')];
        }

        return $periods;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->warning('Команда уже запущена другим процессом — пропускаем.');

            return Command::SUCCESS;
        }

        try {
            $now = $this->clock->now();
            $periods = $this->periodsToRebuild($now);

            $connections = $this->connectionsQuery->execute();

            if (empty($connections)) {
                $io->info('Нет активных SELLER-подключений для пересборки.');

                return Command::SUCCESS;
            }

            $dispatched = 0;
            $failed = 0;

            foreach ($connections as $row) {
                $companyId = (string) $row['company_id'];
                $marketplace = (string) $row['marketplace'];

                foreach ($periods as [$year, $month]) {
                    // Ловим внутри цикла: сбой по текущему месяцу не должен
                    // отменять пересбор предыдущего — ровно ради него цикл и
                    // появился.
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
            }

            $io->success(sprintf(
                'Отправлено %d задач предзакрытия (ошибок: %d), периоды: %s.',
                $dispatched,
                $failed,
                implode(', ', array_map(static fn (array $p): string => sprintf('%d-%02d', $p[0], $p[1]), $periods)),
            ));

            return Command::SUCCESS;
        } finally {
            $this->release();
        }
    }
}
