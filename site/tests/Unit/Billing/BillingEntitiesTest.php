<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Billing\Domain\PaidPeriod;
use App\Billing\Domain\PaymentPlan;
use App\Billing\Domain\PaymentPlanKind;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Entity\Tariff;
use App\Billing\Entity\TariffPrice;
use App\Billing\Enum\TariffCode;
use App\Tests\Builders\Billing\BillingExpiryReminderBuilder;
use App\Tests\Builders\Billing\BillingPaymentBuilder;
use App\Tests\Builders\Billing\CompanySubscriptionBuilder;
use App\Tests\Builders\Billing\TariffBuilder;
use PHPUnit\Framework\TestCase;

final class BillingEntitiesTest extends TestCase
{
    private const OTHER_TARIFF_ID = '01930000-0000-7000-8000-00000000b002';

    public function testTariffPriceAllowsOnlyKnownTermsAndPositiveAmount(): void
    {
        $tariff = TariffBuilder::aTariff()->build();
        self::assertSame(1416000, (new TariffPrice($tariff, 3, 1416000))->getAmountKopecks());

        foreach ([[2, 100], [1, 0]] as [$months, $amount]) {
            try {
                new TariffPrice($tariff, $months, $amount);
                self::fail(sprintf('price %d/%d must be rejected', $months, $amount));
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testPerpetualTariffHasNoPriceAndCannotBePaidForPeriod(): void
    {
        $friend = TariffBuilder::aTariff()->perpetual()->build();

        $this->expectException(\InvalidArgumentException::class);
        new TariffPrice($friend, 1, 100);
    }

    public function testPerpetualTariffCannotStartPaidSubscription(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CompanySubscription::startPaid(
            CompanySubscriptionBuilder::DEFAULT_ID,
            TariffBuilder::aTariff()->perpetual()->build(),
            new PaidPeriod(new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-01-31')),
            new \DateTimeImmutable(),
        );
    }

    public function testPaidPeriodRejectsReversedDates(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PaidPeriod(new \DateTimeImmutable('2027-02-01'), new \DateTimeImmutable('2027-01-31'));
    }

    public function testRenewalKeepsSegmentStartAndNewPeriodResetsIt(): void
    {
        $start = TariffBuilder::aTariff()->build();
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff($start)->paid('2027-01-01', '2027-01-31')->build();

        $subscription->applyPlan($start, self::plan(PaymentPlanKind::RENEWAL, '2027-02-01', '2027-02-28'), self::at('2027-01-25'), self::at('2027-01-25'));
        self::assertSame('2027-01-01', $subscription->getPaidFrom()->format('Y-m-d'));
        self::assertSame('2027-02-28', $subscription->getPaidUntil()?->format('Y-m-d'));

        $subscription->applyPlan($start, self::plan(PaymentPlanKind::NEW_PERIOD, '2027-03-10', '2027-04-09'), self::at('2027-03-10'), self::at('2027-03-10'));
        self::assertSame('2027-03-10', $subscription->getPaidFrom()->format('Y-m-d'));
    }

    public function testUpgradeSwitchesTariffAtOnceAndStartsNewSegment(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff(TariffBuilder::aTariff()->build())->paid('2027-01-01', '2027-01-31')->build();
        $pro = self::pro();

        $subscription->applyPlan($pro, new PaymentPlan(PaymentPlanKind::UPGRADE, self::period('2027-01-16', '2027-02-25'), 10, 160000), self::at('2027-01-16'), self::at('2027-01-16'));

        self::assertSame(TariffCode::PRO, $subscription->getTariff()->getCode());
        self::assertSame('2027-01-16', $subscription->getPaidFrom()->format('Y-m-d'));
        self::assertSame('2027-02-25', $subscription->getPaidUntil()?->format('Y-m-d'));
        self::assertNull($subscription->getNextTariff());
    }

    public function testDowngradeIsScheduledAndTariffOnSwitchesAtDate(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff(self::pro())->paid('2027-01-01', '2027-01-31')->build();
        $start = TariffBuilder::aTariff()->build();

        $subscription->applyPlan($start, self::plan(PaymentPlanKind::DOWNGRADE, '2027-02-01', '2027-02-28'), self::at('2027-01-20'), self::at('2027-01-20'));

        self::assertSame(TariffCode::PRO, $subscription->getTariff()->getCode(), 'сохранённый тариф прежний до даты перехода');
        self::assertSame('2027-02-28', $subscription->getPaidUntil()?->format('Y-m-d'));
        self::assertSame(TariffCode::PRO, $subscription->tariffOn(self::at('2027-01-31'))->getCode());
        self::assertSame(TariffCode::START, $subscription->tariffOn(self::at('2027-02-01'))->getCode());
        self::assertSame($start, $subscription->scheduledTariffAfter(self::at('2027-01-31')));
        self::assertNull($subscription->scheduledTariffAfter(self::at('2027-02-01')));
        self::assertSame('2027-01-01', $subscription->paidFromOn(self::at('2027-01-31'))->format('Y-m-d'));
        self::assertSame('2027-02-01', $subscription->paidFromOn(self::at('2027-02-01'))->format('Y-m-d'));
    }

    public function testRenewalOfScheduledTariffBeforeAndAfterSwitch(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff(self::pro())->paid('2027-01-01', '2027-01-31')->build();
        $start = TariffBuilder::aTariff()->build();
        $subscription->applyPlan($start, self::plan(PaymentPlanKind::DOWNGRADE, '2027-02-01', '2027-02-28'), self::at('2027-01-20'), self::at('2027-01-20'));

        // До перехода: продление запланированного тарифа, переход остаётся.
        $subscription->applyPlan($start, self::plan(PaymentPlanKind::RENEWAL, '2027-03-01', '2027-03-31'), self::at('2027-01-25'), self::at('2027-01-25'));
        self::assertSame(TariffCode::PRO, $subscription->getTariff()->getCode());
        self::assertSame($start, $subscription->getNextTariff());
        self::assertSame('2027-03-31', $subscription->getPaidUntil()?->format('Y-m-d'));

        // После наступления: переход становится текущим тарифом, отрезок — с даты перехода.
        $subscription->applyPlan($start, self::plan(PaymentPlanKind::RENEWAL, '2027-04-01', '2027-04-30'), self::at('2027-02-10'), self::at('2027-02-10'));
        self::assertSame(TariffCode::START, $subscription->getTariff()->getCode());
        self::assertNull($subscription->getNextTariff());
        self::assertNull($subscription->getNextTariffFrom());
        self::assertSame('2027-02-01', $subscription->getPaidFrom()->format('Y-m-d'));
    }

    public function testRenewalOfOtherTariffWhileSwitchScheduledIsRejected(): void
    {
        $pro = self::pro();
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff($pro)->paid('2027-01-01', '2027-01-31')->build();
        $subscription->applyPlan(TariffBuilder::aTariff()->build(), self::plan(PaymentPlanKind::DOWNGRADE, '2027-02-01', '2027-02-28'), self::at('2027-01-20'), self::at('2027-01-20'));

        $this->expectException(\InvalidArgumentException::class);
        $subscription->applyPlan($pro, self::plan(PaymentPlanKind::RENEWAL, '2027-03-01', '2027-03-31'), self::at('2027-01-25'), self::at('2027-01-25'));
    }

    public function testCancelScheduledKeepsCurrentTariffAndNeedsPendingSwitch(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff(self::pro())->paid('2027-01-01', '2027-01-31')->build();

        try {
            $subscription->cancelScheduled(self::at('2027-01-20'), self::at('2027-01-20'));
            self::fail('nothing to cancel must be rejected');
        } catch (\InvalidArgumentException) {
        }

        $subscription->applyPlan(TariffBuilder::aTariff()->build(), self::plan(PaymentPlanKind::DOWNGRADE, '2027-02-01', '2027-02-28'), self::at('2027-01-20'), self::at('2027-01-20'));
        try {
            $subscription->cancelScheduled(self::at('2027-02-01'), self::at('2027-02-01'));
            self::fail('switch already started must not be cancelled');
        } catch (\InvalidArgumentException) {
        }

        $subscription->cancelScheduled(self::at('2027-01-25'), self::at('2027-01-25'));
        self::assertNull($subscription->getNextTariff());
        self::assertSame(TariffCode::PRO, $subscription->tariffOn(self::at('2027-02-10'))->getCode());
        self::assertSame('2027-02-28', $subscription->getPaidUntil()?->format('Y-m-d'));
    }

    public function testNewPeriodAndAssignClearScheduledSwitch(): void
    {
        $start = TariffBuilder::aTariff()->build();
        foreach (['new period', 'assign'] as $case) {
            $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff(self::pro())->paid('2027-01-01', '2027-01-31')->build();
            $subscription->applyPlan($start, self::plan(PaymentPlanKind::DOWNGRADE, '2027-02-01', '2027-02-28'), self::at('2027-01-20'), self::at('2027-01-20'));

            if ('assign' === $case) {
                $subscription->assign(self::pro(), new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-06-30'), self::at('2027-01-21'));
                self::assertSame(TariffCode::PRO, $subscription->tariffOn(self::at('2027-03-01'))->getCode(), $case);
            } else {
                $subscription->applyPlan(self::pro(), self::plan(PaymentPlanKind::NEW_PERIOD, '2027-03-10', '2027-04-09'), self::at('2027-03-10'), self::at('2027-03-10'));
                self::assertSame(TariffCode::PRO, $subscription->getTariff()->getCode(), $case);
            }
            self::assertNull($subscription->getNextTariff(), $case);
            self::assertNull($subscription->getNextTariffFrom(), $case);
        }
    }

    public function testPaymentInvariants(): void
    {
        self::assertSame(497000, BillingPaymentBuilder::aPayment()->build()->getAmountKopecks());

        foreach ([
            'negative amount' => BillingPaymentBuilder::aPayment()->withAmount(1, -1),
            'zero months' => BillingPaymentBuilder::aPayment()->withAmount(0, 100),
            'blank external id' => BillingPaymentBuilder::aPayment()->withExternalId(' '),
            'perpetual tariff' => BillingPaymentBuilder::aPayment()->onTariff(TariffBuilder::aTariff()->perpetual()->build()),
            'not uuid' => BillingPaymentBuilder::aPayment()->withId('42'),
        ] as $case => $builder) {
            try {
                $builder->build();
                self::fail($case.' must be rejected');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testAssignPerpetualDropsEndDateAndDatedTariffNeedsValidPeriod(): void
    {
        $friend = TariffBuilder::aTariff()->perpetual()->build();
        $support = TariffBuilder::aTariff()->withId(self::OTHER_TARIFF_ID)->withCode(TariffCode::SUPPORT, 'На сопровождении')->build();

        $subscription = CompanySubscription::assignNew(CompanySubscriptionBuilder::DEFAULT_ID, $friend, new \DateTimeImmutable('2026-11-01'), null, new \DateTimeImmutable());
        self::assertNull($subscription->getPaidUntil());

        $subscription->assign($support, new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2027-11-30'), new \DateTimeImmutable());
        self::assertSame(TariffCode::SUPPORT, $subscription->getTariff()->getCode());
        self::assertSame('2027-11-30', $subscription->getPaidUntil()?->format('Y-m-d'));

        foreach ([
            'perpetual with end date' => fn () => $subscription->assign($friend, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable()),
            'dated without end' => fn () => $subscription->assign($support, new \DateTimeImmutable('2026-11-01'), null, new \DateTimeImmutable()),
            'reversed dates' => fn () => $subscription->assign($support, new \DateTimeImmutable('2027-02-01'), new \DateTimeImmutable('2027-01-31'), new \DateTimeImmutable()),
        ] as $case => $call) {
            try {
                $call();
                self::fail($case.' must be rejected');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testTrialNeedsDatedTariff(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CompanySubscriptionBuilder::aSubscription()->onTariff(TariffBuilder::aTariff()->perpetual()->build())->trial('2027-01-01', '2027-01-14')->build();
    }

    public function testFirstPaymentAfterTrialEndsTrialAndStartsPaidSegment(): void
    {
        $base = TariffBuilder::aTariff()->withCode(TariffCode::BASE, 'База')->build();
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff($base)->trial('2027-01-01', '2027-01-14')->build();
        self::assertTrue($subscription->isTrial());

        $subscription->applyPlan(self::pro(), self::plan(PaymentPlanKind::RENEWAL, '2027-01-15', '2027-02-14'), self::at('2027-01-05'), self::at('2027-01-05'));
        self::assertFalse($subscription->isTrial());
        self::assertSame(TariffCode::PRO, $subscription->getTariff()->getCode());
        self::assertSame('2027-01-15', $subscription->getPaidFrom()->format('Y-m-d'), 'оплаченный отрезок начинается после пробного');
        self::assertSame('2027-02-14', $subscription->getPaidUntil()?->format('Y-m-d'));
    }

    public function testAssignEndsTrial(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->trial('2027-01-01', '2027-01-14')->build();

        $subscription->assign(TariffBuilder::aTariff()->perpetual()->build(), new \DateTimeImmutable('2027-01-03'), null, new \DateTimeImmutable());

        self::assertFalse($subscription->isTrial());
        self::assertNull($subscription->getPaidUntil());
    }

    public function testPaymentIsSupersededOnceAndNotBySelf(): void
    {
        $payment = BillingPaymentBuilder::aPayment()->build();
        $payment->supersede('01930000-0000-7000-8000-00000000b299');
        self::assertSame('01930000-0000-7000-8000-00000000b299', $payment->getSupersededByPaymentId());

        foreach (['twice' => '01930000-0000-7000-8000-00000000b298', 'self' => BillingPaymentBuilder::DEFAULT_ID] as $case => $upgradeId) {
            $target = 'self' === $case ? BillingPaymentBuilder::aPayment()->build() : $payment;
            try {
                $target->supersede($upgradeId);
                self::fail($case.' must be rejected');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testPaymentRejectsNegativeCarried(): void
    {
        self::assertSame(10, BillingPaymentBuilder::aPayment()->withCarried(10, 100)->build()->getCarriedDays());

        $this->expectException(\InvalidArgumentException::class);
        BillingPaymentBuilder::aPayment()->withCarried(-1, 0)->build();
    }

    public function testExpiryReminderKeepsDateAndRejectsNegativeDays(): void
    {
        $reminder = BillingExpiryReminderBuilder::aReminder()->before('2027-03-17', 0)->build();
        self::assertSame('2027-03-17', $reminder->getPaidUntil()->format('Y-m-d'));
        self::assertSame(0, $reminder->getDaysBefore());

        $this->expectException(\InvalidArgumentException::class);
        BillingExpiryReminderBuilder::aReminder()->before('2027-03-17', -1)->build();
    }

    private static function pro(): Tariff
    {
        return TariffBuilder::aTariff()->withId(self::OTHER_TARIFF_ID)->withCode(TariffCode::PRO, 'Профи')->build();
    }

    private static function at(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date.' 12:00:00', new \DateTimeZone('Europe/Moscow'));
    }

    private static function period(string $from, string $until): PaidPeriod
    {
        return new PaidPeriod(new \DateTimeImmutable($from), new \DateTimeImmutable($until));
    }

    private static function plan(PaymentPlanKind $kind, string $from, string $until): PaymentPlan
    {
        return new PaymentPlan($kind, self::period($from, $until));
    }
}
