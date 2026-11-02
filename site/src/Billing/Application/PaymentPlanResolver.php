<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\PaidPeriodCalculator;
use App\Billing\Domain\PaymentPlan;
use App\Billing\Domain\PaymentPlanner;
use App\Billing\Domain\UnusedBalance;
use App\Billing\Entity\BillingPayment;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Entity\Tariff;
use App\Billing\Exception\TariffChangeNotSupportedException;
use App\Billing\Repository\BillingPaymentRepository;
use App\Billing\Repository\TariffPriceRepository;

/**
 * План оплаты (`PaymentPlanner`) с данными из БД: цены справочника и остаток по журналу
 * оплат. Одни правила для зачисления, старта онлайн-оплаты и подсказки на странице тарифа.
 * Только читает.
 */
final class PaymentPlanResolver
{
    public function __construct(
        private readonly PaymentPlanner $planner,
        private readonly TariffPriceRepository $prices,
        private readonly BillingPaymentRepository $payments,
    ) {
    }

    /**
     * @throws TariffChangeNotSupportedException
     */
    public function plan(?CompanySubscription $subscription, Tariff $tariff, \DateTimeImmutable $receivedAt, int $months): PaymentPlan
    {
        $result = $this->planMany($subscription, $receivedAt, [[$tariff, $months]])[0];
        if ($result instanceof TariffChangeNotSupportedException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Планы для нескольких вариантов оплаты разом (подсказка на странице тарифа): справочник цен
     * и журнал оплат читаются один раз.
     *
     * @param list<array{Tariff, int}> $options тариф и срок в месяцах
     *
     * @return list<PaymentPlan|TariffChangeNotSupportedException> в порядке `$options`
     */
    public function planMany(?CompanySubscription $subscription, \DateTimeImmutable $receivedAt, array $options): array
    {
        $prices = $this->prices->amountsByTariffCode();
        $balance = null;
        $unusedBalance = function () use ($subscription, $receivedAt, &$balance): UnusedBalance {
            if (null === $subscription) {
                return UnusedBalance::none();
            }

            return $balance ??= PaymentPlanner::unusedBalance($this->counted($subscription, $receivedAt), PaidPeriodCalculator::day($receivedAt));
        };

        $results = [];
        foreach ($options as [$tariff, $months]) {
            try {
                $results[] = $this->planner->plan($subscription, $tariff, $receivedAt, $months, $prices, $unusedBalance);
            } catch (TariffChangeNotSupportedException $exception) {
                $results[] = $exception;
            }
        }

        return $results;
    }

    /**
     * Оплаты, остаток которых входит в повышение на день `$receivedAt`; после зачисления повышения
     * их помечают перенесёнными (`BillingPayment::supersede()`).
     *
     * @return list<BillingPayment>
     */
    public function counted(CompanySubscription $subscription, \DateTimeImmutable $receivedAt): array
    {
        $day = PaidPeriodCalculator::day($receivedAt);

        return $this->payments->findUnexpiredInSegment($subscription->getCompanyId(), $subscription->tariffOn($day), $subscription->paidFromOn($day), $day);
    }
}
