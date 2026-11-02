<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Application\CancelScheduledTariffAction;
use App\Billing\Domain\PaidPeriod;
use App\Billing\Domain\PaymentPlan;
use App\Billing\Domain\PaymentPlanKind;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Entity\Tariff;
use App\Billing\Enum\TariffCode;
use App\Billing\Exception\InvalidBillingInputException;
use App\Billing\Repository\TariffRepository;
use App\Tests\Builders\Billing\BillingCheckoutBuilder;
use App\Tests\Builders\Billing\BillingPaymentBuilder;
use App\Tests\Builders\Billing\CompanySubscriptionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Ramsey\Uuid\Uuid;

/**
 * Этап 7: отмена запланированного перехода и сохранность журнала оплат при удалении компании.
 * Переход запланирован на 2099 год — «сегодня» по реальным часам до него.
 */
final class CancelScheduledTariffActionTest extends IntegrationTestCase
{
    private const COMPANY_ID = '0193bbbb-0000-7000-8000-0000000000c1';

    protected function setUp(): void
    {
        parent::setUp();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('cancel-next@example.test')->build();
        $this->em->persist($owner);
        $this->em->persist(CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build());
        $this->em->flush();
    }

    public function testCancelKeepsCurrentTariffUntilPaidUntilAndRepeatIsRejected(): void
    {
        $subscription = CompanySubscriptionBuilder::aSubscription()->withId(Uuid::uuid7()->toString())->forCompany(self::COMPANY_ID)->onTariff($this->tariff(TariffCode::PRO))->paid('2027-01-01', '2099-01-31')->build();
        $subscription->applyPlan($this->tariff(TariffCode::START), new PaymentPlan(PaymentPlanKind::DOWNGRADE, new PaidPeriod(new \DateTimeImmutable('2099-02-01'), new \DateTimeImmutable('2099-02-28'))), new \DateTimeImmutable('2027-01-20'), new \DateTimeImmutable('2027-01-20'));
        $this->em->persist($subscription);
        $this->em->flush();

        $this->cancel();

        $subscription = $this->subscription();
        self::assertNull($subscription->getNextTariff());
        self::assertNull($subscription->getNextTariffFrom());
        self::assertSame(TariffCode::PRO, $subscription->tariffOn(new \DateTimeImmutable('2099-02-15'))->getCode());
        self::assertSame('2099-02-28', $subscription->getPaidUntil()?->format('Y-m-d'));

        try {
            $this->cancel();
            self::fail('repeat must be rejected');
        } catch (InvalidBillingInputException $exception) {
            self::assertStringContainsString('нет или он уже наступил', $exception->getMessage());
        }
    }

    public function testNothingToCancelIsRejected(): void
    {
        $this->expectException(InvalidBillingInputException::class);
        $this->cancel();
    }

    public function testPaymentJournalBlocksCompanyDelete(): void
    {
        $this->em->persist(BillingPaymentBuilder::aPayment()->withId(Uuid::uuid7()->toString())->forCompany(self::COMPANY_ID)->onTariff($this->tariff(TariffCode::START))->build());
        $this->em->flush();

        $this->assertCompanyDeleteBlockedAndKept('billing_payments');
    }

    public function testCheckoutJournalBlocksCompanyDelete(): void
    {
        $this->em->persist(BillingCheckoutBuilder::aCheckout()->withId(Uuid::uuid7()->toString())->forCompany(self::COMPANY_ID)->onTariff($this->tariff(TariffCode::START), 1, 497000)->build());
        $this->em->flush();

        $this->assertCompanyDeleteBlockedAndKept('billing_checkouts');
    }

    private function assertCompanyDeleteBlockedAndKept(string $table): void
    {
        $this->connection->executeStatement('SAVEPOINT journal_delete');
        try {
            $this->connection->executeStatement('DELETE FROM companies WHERE id = ?', [self::COMPANY_ID]);
            self::fail('company with '.$table.' must not be deleted');
        } catch (ForeignKeyConstraintViolationException) {
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT journal_delete');
        }

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE company_id = ?', [self::COMPANY_ID]));
    }

    private function cancel(): void
    {
        self::getContainer()->get(CancelScheduledTariffAction::class)(self::COMPANY_ID);
    }

    private function subscription(): CompanySubscription
    {
        $this->em->clear();
        $subscription = $this->em->getRepository(CompanySubscription::class)->findOneBy(['companyId' => self::COMPANY_ID]);
        self::assertNotNull($subscription);

        return $subscription;
    }

    private function tariff(TariffCode $code): Tariff
    {
        $tariff = self::getContainer()->get(TariffRepository::class)->findOneByCode($code);
        self::assertNotNull($tariff);

        return $tariff;
    }
}
