<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use App\Billing\Entity\BillingPayment;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Entity\Tariff;
use App\Billing\Exception\TariffChangeNotSupportedException;

/**
 * Как оплата тарифа меняет подписку (`docs/tasks/billing/TASK.md`, «Смена тарифа»).
 *
 * - Подписки нет, она бессрочная или истекла — новый период с дня зачисления.
 * - Тот же тариф, пробный период или оплата уже запланированного тарифа — продление со дня
 *   после «оплачено по».
 * - Другой публичный тариф при действующем периоде: дороже (по цене за 1 мес) — сразу, с дня
 *   зачисления на N мес плюс дни за остаток; дешевле — со дня после «оплачено по».
 * - Иначе (скрытый тариф без цены, равная цена, запланирован переход на другой тариф) —
 *   `TariffChangeNotSupportedException`.
 *
 * Остаток в рублях считает вызывающий (по журналу оплат) — замыкание вызывается только при
 * повышении. Дни за остаток = остаток × дни нового срока / цена нового тарифа за этот срок по
 * справочнику (не уплаченная сумма: бонусная оплата 0 ₽ или опечатка не сжигает и не раздувает
 * остаток), вниз до целого. Если оплаты не покрывают непрерывно ровно дни до «оплачено по»
 * (период назначен или сокращён администратором) — смена только через администратора.
 */
final readonly class PaymentPlanner
{
    public function __construct(
        private PaidPeriodCalculator $calculator = new PaidPeriodCalculator(),
    ) {
    }

    /**
     * @param array<string, array<int, int>> $prices код тарифа → срок в месяцах → копейки (справочник)
     * @param \Closure(): UnusedBalance $unusedBalance остаток текущего тарифа с дня зачисления
     *
     * @throws TariffChangeNotSupportedException
     */
    public function plan(
        ?CompanySubscription $subscription,
        Tariff $tariff,
        \DateTimeImmutable $receivedAt,
        int $months,
        array $prices,
        \Closure $unusedBalance,
    ): PaymentPlan {
        $day = PaidPeriodCalculator::day($receivedAt);
        $paidUntil = $subscription?->getPaidUntil();
        if (null === $subscription || null === $paidUntil || PaidPeriodCalculator::date($paidUntil) < $day) {
            return new PaymentPlan(PaymentPlanKind::NEW_PERIOD, $this->calculator->next(null, $day, $months));
        }

        $renewal = new PaymentPlan(PaymentPlanKind::RENEWAL, $this->calculator->next($paidUntil, $day, $months));
        if ($subscription->isTrial()) {
            return $renewal;
        }

        $next = $subscription->scheduledTariffAfter($day);
        if (null !== $next) {
            if ($next->getId() === $tariff->getId()) {
                return $renewal;
            }

            throw new TariffChangeNotSupportedException(sprintf('Уже запланирован переход на тариф «%s» с %s — до него можно оплатить только его.', $next->getName(), $subscription->getNextTariffFrom()?->format('d.m.Y') ?? ''));
        }

        $current = $subscription->tariffOn($day);
        if ($current->getId() === $tariff->getId()) {
            return $renewal;
        }

        $currentPrice = $current->isPublic() ? ($prices[$current->getCode()->value][1] ?? null) : null;
        $newPrice = $tariff->isPublic() ? ($prices[$tariff->getCode()->value][1] ?? null) : null;
        if (null === $currentPrice || null === $newPrice || $currentPrice === $newPrice) {
            throw new TariffChangeNotSupportedException(sprintf('Сменить тариф «%s» на «%s» при действующем периоде можно только через администратора.', $current->getName(), $tariff->getName()));
        }

        if ($newPrice < $currentPrice) {
            return new PaymentPlan(PaymentPlanKind::DOWNGRADE, $renewal->period);
        }

        $balance = $unusedBalance();
        // Оплаты должны непрерывно покрывать ровно дни до «оплачено по»: короче — период назначен без
        // оплаты (дни сгорели бы), длиннее — период сокращён вручную (например, после возврата) и
        // остаток вернул бы отменённые дни. Оба случая — через администратора.
        if (null === $balance->coveredUntil || PaidPeriodCalculator::date($balance->coveredUntil)->format('Y-m-d') !== PaidPeriodCalculator::date($paidUntil)->format('Y-m-d')) {
            throw new TariffChangeNotSupportedException(sprintf('Период тарифа «%s» изменён вручную или назначен без оплаты — перейти на «%s» можно через администратора.', $current->getName(), $tariff->getName()));
        }

        $base = $this->calculator->next(null, $day, $months);
        $termPrice = $prices[$tariff->getCode()->value][$months] ?? $newPrice * $months;
        $carriedDays = intdiv($balance->kopecks * self::days($base), $termPrice);

        return new PaymentPlan(
            PaymentPlanKind::UPGRADE,
            new PaidPeriod($base->from, $base->until->modify('+'.$carriedDays.' days')),
            $carriedDays,
            $balance->kopecks,
        );
    }

    /**
     * Неиспользованная с дня `$day` часть оплат: по каждой оплате (сумма + перенесённое) ×
     * неиспользованные дни / дни её периода, вниз до копейки; и до какой даты дни с `$day`
     * непрерывно, без разрывов и пересечений подкреплены оплатами (иначе null).
     *
     * @param iterable<BillingPayment> $payments оплаты текущего отрезка текущего тарифа
     */
    public static function unusedBalance(iterable $payments, \DateTimeImmutable $day): UnusedBalance
    {
        $day = PaidPeriodCalculator::date($day);
        $periods = [];
        $total = 0;
        foreach ($payments as $payment) {
            $period = new PaidPeriod($payment->getPeriodFrom(), $payment->getPeriodUntil());
            if ($period->until < $day) {
                continue;
            }
            $periods[] = $period;
            $unusedDays = self::days(new PaidPeriod(max($period->from, $day), $period->until));
            $total += intdiv(($payment->getAmountKopecks() + $payment->getCarriedKopecks()) * $unusedDays, self::days($period));
        }

        usort($periods, static fn (PaidPeriod $a, PaidPeriod $b): int => $a->from <=> $b->from);
        $coveredUntil = null;
        $next = $day;
        foreach ($periods as $period) {
            // Разрыв или пересечение периодов оплат — период правили вручную (продление и сокращение
            // после возврата): остаток включил бы отменённые дни, подкреплённых дней нет.
            if ($period->from > $next || (null !== $coveredUntil && $period->from <= $coveredUntil)) {
                return new UnusedBalance($total, null);
            }
            $coveredUntil = $period->until;
            $next = $period->until->modify('+1 day');
        }

        return new UnusedBalance($total, $coveredUntil);
    }

    /** Дней в периоде, обе даты включительно. */
    public static function days(PaidPeriod $period): int
    {
        return (int) $period->from->diff($period->until)->days + 1;
    }
}
