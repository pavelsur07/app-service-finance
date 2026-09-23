<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Application\DTO\OzonAccrualSyncPlanResult;
use App\Marketplace\Message\SyncOzonAccrualByDayMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Единственная точка постановки задач загрузки начислений Ozon
 * (/v1/finance/accrual/by-day): cron `ozon-financial-reports:sync`, ручная
 * синхронизация подключения и первичный синк нового подключения.
 *
 * На каждый бизнес-день окна — одно `SyncOzonAccrualByDayMessage`, от новых
 * дней к старым. Сегодняшний день не ставится: начисления за него неполные.
 */
final class OzonAccrualSyncPlanner
{
    /**
     * Первый день, который by-day имеет право заводить.
     *
     * Дни по 07.09.2026 включительно уже покрыты документами снятого формата v3,
     * и их продажи лежат в `marketplace_sales`. Документы by-day с ними не
     * конфликтуют — у by-day свой `document_type`, — но нормализованные строки
     * не разводятся: уникальность `marketplace_sales` стоит на
     * `external_order_id`, а ключи у путей разные (`posting_number` против
     * `ozon-accrual-{posting}-product-{i}`). День, обработанный из обоих
     * документов, дал бы двойной учёт выручки и возвратов.
     *
     * Граница снимается вместе с переносом истории на by-day, а не раньше:
     * перезалив пересекающихся дней требует отдельной сверенной процедуры.
     */
    public const EARLIEST_SAFE_DAY = '2026-09-08';

    private const TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Ставит задачи за [from; to]: начало поднимается до EARLIEST_SAFE_DAY,
     * конец ограничивается вчерашним днём по Москве. Даты берутся календарно
     * (`Y-m-d`), время отбрасывается.
     */
    public function planRange(
        string $companyId,
        string $connectionId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): OzonAccrualSyncPlanResult {
        $requestedFrom = $from->format('Y-m-d');
        $requestedTo = $to->format('Y-m-d');

        if ($requestedFrom > $requestedTo) {
            throw new \DomainException('Дата начала должна быть меньше или равна дате окончания');
        }

        $clampedToSafeDay = $requestedFrom < self::EARLIEST_SAFE_DAY;
        $firstDay = $clampedToSafeDay ? self::EARLIEST_SAFE_DAY : $requestedFrom;

        $yesterday = $this->clock->now()
            ->setTimezone(new \DateTimeZone(self::TIMEZONE))
            ->modify('-1 day')
            ->format('Y-m-d');
        $lastDay = min($requestedTo, $yesterday);

        if ($firstDay > $lastDay) {
            return new OzonAccrualSyncPlanResult(0, null, null, $clampedToSafeDay);
        }

        $dispatched = 0;
        $day = new \DateTimeImmutable($lastDay, new \DateTimeZone(self::TIMEZONE));
        $first = new \DateTimeImmutable($firstDay, new \DateTimeZone(self::TIMEZONE));

        while ($day >= $first) {
            $date = $day->format('Y-m-d');

            $this->messageBus->dispatch(new SyncOzonAccrualByDayMessage($companyId, $connectionId, $date));
            ++$dispatched;

            $this->logger->info('Dispatched Ozon accrual by-day sync message', [
                'company_id' => $companyId,
                'connection_id' => $connectionId,
                'date' => $date,
            ]);

            $day = $day->modify('-1 day');
        }

        return new OzonAccrualSyncPlanResult($dispatched, $firstDay, $lastDay, $clampedToSafeDay);
    }
}
