<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Billing\Domain\PaidPeriod;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Entity\TariffPrice;
use App\Billing\Enum\TariffCode;
use App\Billing\Exception\TariffChangeNotSupportedException;
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

    public function testRenewalBaseForSameTariff(): void
    {
        $start = TariffBuilder::aTariff()->build();
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff($start)->paid('2027-01-01', '2027-01-31')->build();

        self::assertSame('2027-01-31', $subscription->renewalBase($start, new \DateTimeImmutable('2027-01-25 12:00'))?->format('Y-m-d'));
        self::assertNull($subscription->renewalBase($start, new \DateTimeImmutable('2027-02-03 12:00')), 'expired → from received day');
    }

    public function testOtherTariffDuringActivePeriodIsRejected(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff(TariffBuilder::aTariff()->build())->paid('2027-01-01', '2027-01-31')->build();
        $pro = TariffBuilder::aTariff()->withId(self::OTHER_TARIFF_ID)->withCode(TariffCode::PRO, 'Профи')->build();

        $this->expectException(TariffChangeNotSupportedException::class);
        $subscription->renewalBase($pro, new \DateTimeImmutable('2027-01-31 23:00'));
    }

    public function testOtherTariffAfterExpiryOrFromPerpetualStartsFresh(): void
    {
        $pro = TariffBuilder::aTariff()->withId(self::OTHER_TARIFF_ID)->withCode(TariffCode::PRO, 'Профи')->build();
        $expired = CompanySubscriptionBuilder::aSubscription()->paid('2027-01-01', '2027-01-31')->build();
        $friend = CompanySubscriptionBuilder::aSubscription()->perpetualFrom('2026-11-01')->build();

        self::assertNull($expired->renewalBase($pro, new \DateTimeImmutable('2027-02-01 10:00')));
        self::assertNull($friend->renewalBase($pro, new \DateTimeImmutable('2027-02-01 10:00')));
    }

    public function testApplyPaidPeriodKeepsContinuousStartAndResetsAfterGap(): void
    {
        $start = TariffBuilder::aTariff()->build();
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff($start)->paid('2027-01-01', '2027-01-31')->build();

        $subscription->applyPaidPeriod($start, new PaidPeriod(new \DateTimeImmutable('2027-02-01'), new \DateTimeImmutable('2027-02-28')), new \DateTimeImmutable());
        self::assertSame('2027-01-01', $subscription->getPaidFrom()->format('Y-m-d'));
        self::assertSame('2027-02-28', $subscription->getPaidUntil()?->format('Y-m-d'));

        $subscription->applyPaidPeriod($start, new PaidPeriod(new \DateTimeImmutable('2027-03-10'), new \DateTimeImmutable('2027-04-09')), new \DateTimeImmutable());
        self::assertSame('2027-03-10', $subscription->getPaidFrom()->format('Y-m-d'));
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

    public function testPaymentDuringTrialContinuesFromTrialEndEvenForOtherTariff(): void
    {
        $base = TariffBuilder::aTariff()->withCode(TariffCode::BASE, 'База')->build();
        $pro = TariffBuilder::aTariff()->withId(self::OTHER_TARIFF_ID)->withCode(TariffCode::PRO, 'Профи')->build();
        $subscription = CompanySubscriptionBuilder::aSubscription()->onTariff($base)->trial('2027-01-01', '2027-01-14')->build();
        self::assertTrue($subscription->isTrial());

        // Другой тариф во время пробного — не отказ, продолжение от конца пробного.
        $base = $subscription->renewalBase($pro, new \DateTimeImmutable('2027-01-05 12:00:00', new \DateTimeZone('Europe/Moscow')));
        self::assertSame('2027-01-14', $base?->format('Y-m-d'));

        $subscription->applyPaidPeriod($pro, new PaidPeriod(new \DateTimeImmutable('2027-01-15'), new \DateTimeImmutable('2027-02-14')), new \DateTimeImmutable());
        self::assertFalse($subscription->isTrial());
        self::assertSame(TariffCode::PRO, $subscription->getTariff()->getCode());
        self::assertSame('2027-01-15', $subscription->getPaidFrom()->format('Y-m-d'), 'оплаченный отрезок начинается после пробного');
        self::assertSame('2027-02-14', $subscription->getPaidUntil()?->format('Y-m-d'));
    }

    public function testPaymentAfterExpiredTrialStartsFromReceivedDay(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->trial('2027-01-01', '2027-01-14')->build();

        self::assertNull($subscription->renewalBase(TariffBuilder::aTariff()->withId(self::OTHER_TARIFF_ID)->build(), new \DateTimeImmutable('2027-01-20 10:00:00', new \DateTimeZone('Europe/Moscow'))));
    }

    public function testAssignEndsTrial(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->trial('2027-01-01', '2027-01-14')->build();

        $subscription->assign(TariffBuilder::aTariff()->perpetual()->build(), new \DateTimeImmutable('2027-01-03'), null, new \DateTimeImmutable());

        self::assertFalse($subscription->isTrial());
        self::assertNull($subscription->getPaidUntil());
    }

    public function testExpiryReminderKeepsDateAndRejectsNegativeDays(): void
    {
        $reminder = BillingExpiryReminderBuilder::aReminder()->before('2027-03-17', 0)->build();
        self::assertSame('2027-03-17', $reminder->getPaidUntil()->format('Y-m-d'));
        self::assertSame(0, $reminder->getDaysBefore());

        $this->expectException(\InvalidArgumentException::class);
        BillingExpiryReminderBuilder::aReminder()->before('2027-03-17', -1)->build();
    }
}
