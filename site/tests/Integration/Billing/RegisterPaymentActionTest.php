<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Application\DTO\RegisterPaymentCommand;
use App\Billing\Application\RegisterPaymentAction;
use App\Billing\Entity\BillingPayment;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Enum\PaymentSource;
use App\Billing\Enum\TariffCode;
use App\Billing\Exception\InvalidBillingInputException;
use App\Billing\Exception\PerpetualTariffChangeNotConfirmedException;
use App\Billing\Exception\TariffChangeNotSupportedException;
use App\Billing\Exception\TariffNotPayableException;
use App\Billing\Repository\TariffRepository;
use App\Tests\Builders\Billing\CompanySubscriptionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ramsey\Uuid\Uuid;

final class RegisterPaymentActionTest extends IntegrationTestCase
{
    private const COMPANY_ID = '0193bbbb-0000-7000-8000-0000000000b1';

    protected function setUp(): void
    {
        parent::setUp();
        $owner = UserBuilder::aUser()->build();
        $this->em->persist($owner);
        $this->em->persist(CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build());
        $this->em->flush();
    }

    public function testFirstPaymentCreatesSubscriptionFromReceivedDay(): void
    {
        $paymentId = $this->pay(TariffCode::START, '2027-01-15 15:00:00');

        $payment = $this->connection->fetchAssociative('SELECT company_id, months, amount_kopecks, period_from, period_until, source FROM billing_payments WHERE id = ?', [$paymentId]);
        self::assertSame(['company_id' => self::COMPANY_ID, 'months' => 1, 'amount_kopecks' => 497000, 'period_from' => '2027-01-15', 'period_until' => '2027-02-14', 'source' => 'manual_invoice'], $payment);

        $subscription = $this->subscription();
        self::assertSame(TariffCode::START, $subscription->getTariff()->getCode());
        self::assertSame('2027-01-15', $subscription->getPaidFrom()->format('Y-m-d'));
        self::assertSame('2027-02-14', $subscription->getPaidUntil()?->format('Y-m-d'));
    }

    public function testRenewalBeforeEndContinuesPeriod(): void
    {
        $this->pay(TariffCode::START, '2027-01-15 15:00:00');
        $this->pay(TariffCode::START, '2027-02-10 11:00:00', months: 3, amount: 1416000);

        $subscription = $this->subscription();
        self::assertSame('2027-01-15', $subscription->getPaidFrom()->format('Y-m-d'));
        self::assertSame('2027-05-14', $subscription->getPaidUntil()?->format('Y-m-d'));
        self::assertSame(2, $this->paymentCount());
    }

    public function testPaymentAfterEndStartsNewPeriodAndMaySwitchTariff(): void
    {
        $this->pay(TariffCode::START, '2027-01-15 15:00:00');
        $this->pay(TariffCode::PRO, '2027-02-20 11:00:00', amount: 1479000);

        $subscription = $this->subscription();
        self::assertSame(TariffCode::PRO, $subscription->getTariff()->getCode());
        self::assertSame('2027-02-20', $subscription->getPaidFrom()->format('Y-m-d'));
        self::assertSame('2027-03-19', $subscription->getPaidUntil()?->format('Y-m-d'));
    }

    public function testRepeatedCallWithSameIdReturnsSamePaymentAndRowCount(): void
    {
        $paymentId = Uuid::uuid7()->toString();

        self::assertSame($paymentId, $this->pay(TariffCode::START, '2027-01-15 15:00:00', paymentId: $paymentId));
        $this->em->clear();
        self::assertSame($paymentId, $this->pay(TariffCode::START, '2027-01-15 15:00:00', paymentId: $paymentId));

        self::assertSame(1, $this->paymentCount());
        self::assertSame('2027-02-14', $this->subscription()->getPaidUntil()?->format('Y-m-d'), 'period is not extended twice');
    }

    public function testRepeatedExternalIdWithNewIdReturnsOriginalPayment(): void
    {
        $first = $this->pay(TariffCode::START, '2027-01-15 15:00:00', externalId: 'invoice-77');
        $this->em->clear();
        $second = $this->pay(TariffCode::START, '2027-01-15 15:00:00', externalId: 'invoice-77');

        self::assertSame($first, $second);
        self::assertSame(1, $this->paymentCount());
    }

    public function testOtherTariffDuringActivePeriodIsRejectedAndWritesNothing(): void
    {
        $this->pay(TariffCode::START, '2027-01-15 15:00:00');
        $this->em->clear();

        try {
            $this->pay(TariffCode::PRO, '2027-02-01 10:00:00', amount: 1479000);
            self::fail('tariff change during active period must be rejected');
        } catch (TariffChangeNotSupportedException) {
        }

        self::assertSame(1, $this->paymentCount());
    }

    public function testPerpetualTariffIsNotPayable(): void
    {
        $this->expectException(TariffNotPayableException::class);

        $this->pay(TariffCode::FRIEND, '2027-01-15 15:00:00');
    }

    public function testPaymentMovesPerpetualSubscriptionToPaidTariff(): void
    {
        $friend = self::getContainer()->get(TariffRepository::class)->findOneByCode(TariffCode::FRIEND);
        self::assertNotNull($friend);
        $this->em->persist(CompanySubscriptionBuilder::aSubscription()->forCompany(self::COMPANY_ID)->onTariff($friend)->perpetualFrom('2026-11-01')->build());
        $this->em->flush();

        try {
            $this->pay(TariffCode::BASE, '2027-01-15 15:00:00', amount: 995000);
            self::fail('leaving perpetual tariff requires confirmation');
        } catch (PerpetualTariffChangeNotConfirmedException) {
        }
        self::assertSame(0, $this->paymentCount());
        self::assertTrue($this->em->isOpen(), 'pre-transaction rejection keeps EntityManager open');

        $this->pay(TariffCode::BASE, '2027-01-15 15:00:00', amount: 995000, confirmLeavePerpetual: true);

        $subscription = $this->subscription();
        self::assertSame(TariffCode::BASE, $subscription->getTariff()->getCode());
        self::assertSame('2027-02-14', $subscription->getPaidUntil()?->format('Y-m-d'));
    }

    public function testSameTariffPaidDuringGraceStartsFromReceivedDay(): void
    {
        $this->pay(TariffCode::START, '2027-01-15 15:00:00');
        $this->pay(TariffCode::START, '2027-02-17 10:00:00');

        $subscription = $this->subscription();
        self::assertSame('2027-02-17', $subscription->getPaidFrom()->format('Y-m-d'), 'gap resets continuous start');
        self::assertSame('2027-03-16', $subscription->getPaidUntil()?->format('Y-m-d'));
    }

    public function testInactiveTariffIsNotPayable(): void
    {
        $this->connection->executeStatement("UPDATE billing_tariffs SET is_active = FALSE WHERE code = 'pro'");

        try {
            $this->pay(TariffCode::PRO, '2027-01-15 15:00:00', amount: 1479000);
            self::fail('inactive tariff must not be payable');
        } catch (TariffNotPayableException) {
        }

        self::assertSame(0, $this->paymentCount());
        self::assertTrue($this->em->isOpen(), 'domain rejection must not close the EntityManager');
    }

    /**
     * Чужой paymentId: до вставки повтор не виден (фильтр по компании), unique PK срабатывает,
     * поиск DBAL после rollback не находит оплату этой компании — исключение пробрасывается.
     */
    public function testPaymentIdOfOtherCompanyIsNotTreatedAsRepeat(): void
    {
        $otherCompanyId = '0193bbbb-0000-7000-8000-0000000000b2';
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('billing-other@example.test')->build();
        $this->em->persist($owner);
        $this->em->persist(CompanyBuilder::aCompany()->withId($otherCompanyId)->withOwner($owner)->build());
        $this->em->flush();

        $paymentId = Uuid::uuid7()->toString();
        self::getContainer()->get(RegisterPaymentAction::class)(new RegisterPaymentCommand(
            $paymentId, $otherCompanyId, TariffCode::START, 1, 497000,
            new \DateTimeImmutable('2027-01-15 15:00:00'), PaymentSource::MANUAL_INVOICE,
        ));
        $this->em->clear(); // другой запрос — свой UnitOfWork

        $this->expectException(UniqueConstraintViolationException::class);
        $this->pay(TariffCode::START, '2027-01-15 15:00:00', paymentId: $paymentId);
    }

    /**
     * «Друга» назначили между проверкой до транзакции и блокировкой: проверка под блокировкой
     * (после refresh) не даёт снять бессрочный тариф без подтверждения.
     */
    public function testPerpetualAssignedConcurrentlyIsCheckedUnderLock(): void
    {
        $this->pay(TariffCode::START, '2027-01-15 15:00:00');
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(CompanySubscription::class)->findOneBy(['companyId' => self::COMPANY_ID]));

        // Параллельное назначение «Друга» мимо этого EntityManager.
        $this->connection->executeStatement(
            "UPDATE billing_company_subscriptions SET tariff_id = (SELECT id FROM billing_tariffs WHERE code = 'friend'), paid_until = NULL WHERE company_id = ?",
            [self::COMPANY_ID],
        );

        try {
            $this->pay(TariffCode::START, '2027-02-10 11:00:00');
            self::fail('stale pre-check must be repeated under the lock');
        } catch (PerpetualTariffChangeNotConfirmedException) {
        }

        self::assertSame(1, $this->paymentCount());
    }

    public function testAmountIsLimitedByColumnRange(): void
    {
        $max = RegisterPaymentAction::MAX_AMOUNT_KOPECKS;
        $this->pay(TariffCode::START, '2027-01-15 15:00:00', amount: $max);
        self::assertSame($max, (int) $this->connection->fetchOne('SELECT amount_kopecks FROM billing_payments WHERE company_id = ?', [self::COMPANY_ID]));

        $this->expectException(InvalidBillingInputException::class);
        $this->pay(TariffCode::START, '2027-01-15 15:00:00', amount: $max + 1);
    }

    /**
     * Подписка, загруженная в identity map до блокировки и изменённая другой транзакцией,
     * перечитывается: продление считается от актуального paidUntil.
     */
    public function testRenewalUsesFreshSubscriptionNotStaleIdentityMap(): void
    {
        $this->pay(TariffCode::START, '2027-01-15 15:00:00');
        $this->em->clear();
        $loaded = $this->em->getRepository(CompanySubscription::class)->findOneBy(['companyId' => self::COMPANY_ID]);
        self::assertSame('2027-02-14', $loaded?->getPaidUntil()?->format('Y-m-d'));

        // «Параллельное» продление, закоммиченное мимо этого EntityManager.
        $this->connection->executeStatement("UPDATE billing_company_subscriptions SET paid_until = '2027-03-14' WHERE company_id = ?", [self::COMPANY_ID]);

        $this->pay(TariffCode::START, '2027-02-10 11:00:00');

        self::assertSame('2027-04-14', $this->subscription()->getPaidUntil()?->format('Y-m-d'));
    }

    public function testReceivedMomentFromOtherTimezoneIsStoredWithoutShift(): void
    {
        $paymentId = self::getContainer()->get(RegisterPaymentAction::class)(new RegisterPaymentCommand(
            Uuid::uuid7()->toString(),
            self::COMPANY_ID,
            TariffCode::START,
            1,
            497000,
            new \DateTimeImmutable('2027-01-31 21:30:00', new \DateTimeZone('UTC')),
            PaymentSource::MANUAL_INVOICE,
        ));
        $this->em->clear();

        $payment = $this->em->find(BillingPayment::class, $paymentId);
        self::assertNotNull($payment);
        self::assertSame('2027-01-31T21:30:00+00:00', $payment->getReceivedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        self::assertSame('2027-02-01', $payment->getPeriodFrom()->format('Y-m-d'));
    }

    /**
     * Зачисление по компании ждёт блокировку, которую держит параллельная транзакция,
     * и до её снятия ничего не пишет (здесь — до lock_timeout).
     */
    public function testWaitsForConcurrentPaymentOfSameCompany(): void
    {
        $worker = DriverManager::getConnection($this->connection->getParams());
        try {
            $worker->beginTransaction();
            $worker->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => 'billing_subscription:'.self::COMPANY_ID]);
            $this->connection->executeStatement("SET lock_timeout = '300ms'");

            try {
                $this->pay(TariffCode::START, '2027-01-15 15:00:00');
                self::fail('payment must wait for the concurrent one');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState(), 'lock timeout');
            }

            self::assertSame(0, $this->paymentCount());
        } finally {
            $this->connection->executeStatement('SET lock_timeout = 0');
            $worker->rollBack();
            $worker->close();
        }
    }

    private function pay(
        TariffCode $tariff,
        string $receivedAt,
        int $months = 1,
        int $amount = 497000,
        ?string $paymentId = null,
        ?string $externalId = null,
        bool $confirmLeavePerpetual = false,
    ): string {
        return self::getContainer()->get(RegisterPaymentAction::class)(new RegisterPaymentCommand(
            $paymentId ?? Uuid::uuid7()->toString(),
            self::COMPANY_ID,
            $tariff,
            $months,
            $amount,
            new \DateTimeImmutable($receivedAt, new \DateTimeZone('Europe/Moscow')),
            PaymentSource::MANUAL_INVOICE,
            $externalId,
            $confirmLeavePerpetual,
        ));
    }

    private function subscription(): CompanySubscription
    {
        $this->em->clear();
        $subscription = $this->em->getRepository(CompanySubscription::class)->findOneBy(['companyId' => self::COMPANY_ID]);
        self::assertNotNull($subscription);

        return $subscription;
    }

    private function paymentCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM billing_payments WHERE company_id = ?', [self::COMPANY_ID]);
    }
}
