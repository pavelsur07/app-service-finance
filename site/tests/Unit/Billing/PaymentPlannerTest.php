<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Billing\Domain\PaidPeriod;
use App\Billing\Domain\PaymentPlan;
use App\Billing\Domain\PaymentPlanKind;
use App\Billing\Domain\PaymentPlanner;
use App\Billing\Domain\UnusedBalance;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Entity\Tariff;
use App\Billing\Enum\TariffCode;
use App\Billing\Exception\TariffChangeNotSupportedException;
use App\Tests\Builders\Billing\BillingPaymentBuilder;
use App\Tests\Builders\Billing\CompanySubscriptionBuilder;
use App\Tests\Builders\Billing\TariffBuilder;
use PHPUnit\Framework\TestCase;

final class PaymentPlannerTest extends TestCase
{
    private const PRICES = ['start' => [1 => 497000], 'base' => [1 => 995000], 'pro' => [1 => 1479000, 3 => 4215000]];

    private Tariff $start;
    private Tariff $base;
    private Tariff $pro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->start = TariffBuilder::aTariff()->build();
        $this->base = TariffBuilder::aTariff()->withId('01930000-0000-7000-8000-00000000b003')->withCode(TariffCode::BASE, 'База')->build();
        $this->pro = TariffBuilder::aTariff()->withId('01930000-0000-7000-8000-00000000b002')->withCode(TariffCode::PRO, 'Профи')->build();
    }

    public function testNoSubscriptionExpiredOrPerpetualStartsNewPeriodFromReceivedDay(): void
    {
        $cases = [
            'no subscription' => null,
            'expired' => CompanySubscriptionBuilder::aSubscription()->onTariff($this->pro)->paid('2027-01-01', '2027-01-31')->build(),
            'expired trial' => CompanySubscriptionBuilder::aSubscription()->trial('2027-01-01', '2027-01-14')->build(),
            'perpetual' => CompanySubscriptionBuilder::aSubscription()->perpetualFrom('2026-11-01')->build(),
        ];
        foreach ($cases as $case => $subscription) {
            $plan = $this->plan($subscription, $this->start, '2027-02-05');

            self::assertSame(PaymentPlanKind::NEW_PERIOD, $plan->kind, $case);
            self::assertPeriod('2027-02-05', '2027-03-04', $plan, $case);
            self::assertSame(0, $plan->carriedDays, $case);
        }
    }

    public function testSameTariffRenewsFromDayAfterPaidUntil(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff($this->start)->paid('2027-01-01', '2027-01-31')->build();

        $plan = $this->plan($subscription, $this->start, '2027-01-31', months: 3);

        self::assertSame(PaymentPlanKind::RENEWAL, $plan->kind);
        self::assertPeriod('2027-02-01', '2027-04-30', $plan);
    }

    public function testAnyTariffDuringTrialContinuesFromTrialEnd(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff($this->base)->trial('2027-01-01', '2027-01-14')->build();

        $plan = $this->plan($subscription, $this->start, '2027-01-05');

        self::assertSame(PaymentPlanKind::RENEWAL, $plan->kind);
        self::assertPeriod('2027-01-15', '2027-02-14', $plan);
    }

    public function testUpgradeStartsTodayAndConvertsUnusedBalanceToDays(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff($this->start)->paid('2027-01-01', '2027-01-31')->build();
        // «Старт» 01.01–31.01 за 4 970 ₽: с 16.01 не использовано 16 дн из 31 → 2 565,16 ₽.
        $balance = PaymentPlanner::unusedBalance([BillingPaymentBuilder::aPayment()->forPeriod('2027-01-01', '2027-01-31')->build()], new \DateTimeImmutable('2027-01-16'));
        self::assertSame(256516, $balance->kopecks);
        self::assertSame('2027-01-31', $balance->coveredUntil?->format('Y-m-d'));

        $plan = $this->plan($subscription, $this->pro, '2027-01-16', unused: $balance->kopecks);

        // «Профи» 16.01–15.02 (31 дн), цена 14 790 ₽: 2 565,16 × 31 / 14 790 = 5,37 → +5 дн.
        self::assertSame(PaymentPlanKind::UPGRADE, $plan->kind);
        self::assertPeriod('2027-01-16', '2027-02-20', $plan);
        self::assertSame(5, $plan->carriedDays);
        self::assertSame(256516, $plan->carriedKopecks);
    }

    public function testUpgradeConvertsBalanceByListPriceOfChosenTerm(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff($this->start)->paid('2027-01-01', '2027-01-31')->build();

        // «Профи» 3 мес 16.01–15.04 (90 дн), цена 42 150 ₽: 2 565,16 × 90 / 42 150 = 5,47 → +5 дн.
        $plan = $this->plan($subscription, $this->pro, '2027-01-16', months: 3, unused: 256516);
        self::assertPeriod('2027-01-16', '2027-04-20', $plan);

        foreach (['no balance' => 0, 'balance too small for a day' => 40000] as $case => $unused) {
            $plan = $this->plan($subscription, $this->pro, '2027-01-16', unused: $unused);

            self::assertSame(PaymentPlanKind::UPGRADE, $plan->kind, $case);
            self::assertPeriod('2027-01-16', '2027-02-15', $plan, $case);
            self::assertSame(0, $plan->carriedDays, $case);
        }
    }

    public function testUpgradeIsRejectedWhenDaysAreNotBackedByPayments(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff($this->start)->paid('2027-01-01', '2027-10-31')->build();

        foreach (['no payments' => null, 'payments end before paid until' => '2027-01-31', 'period shortened by admin' => '2027-11-30'] as $case => $coveredUntil) {
            try {
                $this->plan($subscription, $this->pro, '2027-01-16', unused: 256516, coveredUntil: $coveredUntil);
                self::fail($case.' must be rejected');
            } catch (TariffChangeNotSupportedException $exception) {
                self::assertStringContainsString('через администратора', $exception->getMessage(), $case);
            }
        }
    }

    public function testUnusedBalanceSumsUnexpiredPaymentsWithCarriedPart(): void
    {
        $payments = [
            BillingPaymentBuilder::aPayment()->forPeriod('2027-02-21', '2027-03-20')->withAmount(1, 1479000)->build(), // не начиналась
            BillingPaymentBuilder::aPayment()->forPeriod('2026-12-01', '2026-12-31')->build(), // закончилась
            BillingPaymentBuilder::aPayment()->forPeriod('2027-01-16', '2027-02-20')->withAmount(1, 1479000)->withCarried(5, 256516)->build(),
        ];

        // 10.02: у третьей 11 дн из 36 → (14 790 + 2 565,16) × 11 / 36 = 5 302,96; первая целиком.
        $balance = PaymentPlanner::unusedBalance($payments, new \DateTimeImmutable('2027-02-10'));
        self::assertSame(530296 + 1479000, $balance->kopecks);
        self::assertSame('2027-03-20', $balance->coveredUntil?->format('Y-m-d'));

        $withGap = PaymentPlanner::unusedBalance([$payments[2], BillingPaymentBuilder::aPayment()->forPeriod('2027-03-01', '2027-03-31')->build()], new \DateTimeImmutable('2027-02-10'));
        self::assertNull($withGap->coveredUntil, 'разрыв периодов — подкреплённых дней нет');
        self::assertNull(PaymentPlanner::unusedBalance([], new \DateTimeImmutable('2027-02-10'))->coveredUntil);

        $overlap = PaymentPlanner::unusedBalance([
            BillingPaymentBuilder::aPayment()->forPeriod('2027-01-01', '2027-01-31')->build(),
            BillingPaymentBuilder::aPayment()->forPeriod('2027-01-16', '2027-02-15')->build(),
        ], new \DateTimeImmutable('2027-01-20'));
        self::assertNull($overlap->coveredUntil, 'пересечение периодов — подкреплённых дней нет');
    }

    public function testBalanceIsReadOnlyForUpgrade(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff($this->pro)->paid('2027-01-01', '2027-01-31')->build();
        $called = false;
        $spy = static function () use (&$called): UnusedBalance {
            $called = true;

            return UnusedBalance::none();
        };

        (new PaymentPlanner())->plan($subscription, $this->pro, self::at('2027-01-20'), 1, self::PRICES, $spy);
        (new PaymentPlanner())->plan($subscription, $this->start, self::at('2027-01-20'), 1, self::PRICES, $spy);

        self::assertFalse($called);
    }

    public function testDowngradeStartsAfterPaidUntil(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff($this->pro)->paid('2027-01-01', '2027-01-31')->build();

        $plan = $this->plan($subscription, $this->start, '2027-01-20');

        self::assertSame(PaymentPlanKind::DOWNGRADE, $plan->kind);
        self::assertPeriod('2027-02-01', '2027-02-28', $plan);
    }

    public function testScheduledSwitchAllowsOnlyItsTariffUntilItStarts(): void
    {
        $subscription = $this->scheduledStartAfterPro();

        $renewal = $this->plan($subscription, $this->start, '2027-01-25');
        self::assertSame(PaymentPlanKind::RENEWAL, $renewal->kind);
        self::assertPeriod('2027-03-01', '2027-03-31', $renewal);

        foreach ([$this->pro, $this->base] as $tariff) {
            try {
                $this->plan($subscription, $tariff, '2027-01-25');
                self::fail($tariff->getName().' must be rejected while switch is scheduled');
            } catch (TariffChangeNotSupportedException $exception) {
                self::assertStringContainsString('01.02.2027', $exception->getMessage());
            }
        }
    }

    public function testAfterScheduledSwitchStartsItIsTheCurrentTariff(): void
    {
        $subscription = $this->scheduledStartAfterPro();

        self::assertSame(PaymentPlanKind::RENEWAL, $this->plan($subscription, $this->start, '2027-02-10')->kind);
        self::assertSame(PaymentPlanKind::UPGRADE, $this->plan($subscription, $this->base, '2027-02-10')->kind);
    }

    public function testHiddenTariffsAndEqualPriceAreRejected(): void
    {
        $support = TariffBuilder::aTariff()->withId('01930000-0000-7000-8000-00000000b004')->withCode(TariffCode::SUPPORT, 'На сопровождении')->hidden()->build();
        $onStart = CompanySubscriptionBuilder::aSubscription()->onTariff($this->start)->paid('2027-01-01', '2027-01-31')->build();
        $onSupport = CompanySubscriptionBuilder::aSubscription()->onTariff($support)->paid('2027-01-01', '2027-12-31')->build();
        $pricesWithSupport = self::PRICES + ['support' => [1 => 2000000]];

        $cases = [
            'to hidden' => [$onStart, $support, $pricesWithSupport],
            'from hidden' => [$onSupport, $this->pro, $pricesWithSupport],
            'equal price' => [$onStart, $this->base, ['start' => [1 => 497000], 'base' => [1 => 497000]]],
            'no price' => [$onStart, $this->pro, ['start' => [1 => 497000]]],
        ];
        $rejected = [];
        foreach ($cases as $case => [$subscription, $tariff, $prices]) {
            try {
                (new PaymentPlanner())->plan($subscription, $tariff, self::at('2027-01-20'), 1, $prices, static fn (): UnusedBalance => UnusedBalance::none());
            } catch (TariffChangeNotSupportedException) {
                $rejected[] = $case;
            }
        }
        self::assertSame(array_keys($cases), $rejected);
    }

    private function scheduledStartAfterPro(): CompanySubscription
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff($this->pro)->paid('2027-01-01', '2027-01-31')->build();
        $subscription->applyPlan($this->start, new PaymentPlan(PaymentPlanKind::DOWNGRADE, new PaidPeriod(new \DateTimeImmutable('2027-02-01'), new \DateTimeImmutable('2027-02-28'))), self::at('2027-01-20'), self::at('2027-01-20'));

        return $subscription;
    }

    /**
     * @param string|null $coveredUntil по умолчанию — «оплачено по» подписки (оплаты покрывают период)
     */
    private function plan(?CompanySubscription $subscription, Tariff $tariff, string $day, int $months = 1, int $unused = 0, ?string $coveredUntil = 'paid'): PaymentPlan
    {
        if ('paid' === $coveredUntil) {
            $coveredUntil = $subscription?->getPaidUntil()?->format('Y-m-d');
        }
        $balance = new UnusedBalance($unused, null === $coveredUntil ? null : new \DateTimeImmutable($coveredUntil));

        return (new PaymentPlanner())->plan($subscription, $tariff, self::at($day), $months, self::PRICES, static fn (): UnusedBalance => $balance);
    }

    private static function at(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date.' 12:00:00', new \DateTimeZone('Europe/Moscow'));
    }

    private static function assertPeriod(string $from, string $until, PaymentPlan $plan, string $message = ''): void
    {
        self::assertSame($from.' — '.$until, $plan->period->from->format('Y-m-d').' — '.$plan->period->until->format('Y-m-d'), $message);
    }
}
