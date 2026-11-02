<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Application\AssignTariffAction;
use App\Billing\Application\DTO\AssignTariffCommand;
use App\Billing\Application\DTO\RegisterPaymentCommand;
use App\Billing\Application\RegisterPaymentAction;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Enum\AccessStatus;
use App\Billing\Enum\PaymentSource;
use App\Billing\Enum\TariffCode;
use App\Billing\Exception\InvalidBillingInputException;
use App\Billing\Facade\BillingFacadeInterface;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class AssignTariffActionTest extends IntegrationTestCase
{
    private const COMPANY_ID = '0193bbbb-0000-7000-8000-0000000000a1';
    private const OTHER_COMPANY_ID = '0193bbbb-0000-7000-8000-0000000000a2';

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([self::COMPANY_ID, self::OTHER_COMPANY_ID] as $i => $companyId) {
            $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail(sprintf('assign-%d@example.test', $i))->build();
            $this->em->persist($owner);
            $this->em->persist(CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build());
        }
        $this->em->flush();
    }

    public function testAssignsPerpetualAndIgnoresUntil(): void
    {
        $this->assign(TariffCode::FRIEND, '2026-11-01', '2027-01-01');

        $subscription = $this->subscription();
        self::assertSame(TariffCode::FRIEND, $subscription->getTariff()->getCode());
        self::assertNull($subscription->getPaidUntil());
    }

    public function testChangesExistingSubscriptionToDatedTariff(): void
    {
        $this->assign(TariffCode::FRIEND, '2026-11-01', null);
        $this->assign(TariffCode::SUPPORT, '2026-12-01', '2027-11-30');
        $this->assign(TariffCode::SUPPORT, '2026-12-01', '2027-11-30'); // повтор — то же состояние

        $subscription = $this->subscription();
        self::assertSame(TariffCode::SUPPORT, $subscription->getTariff()->getCode());
        self::assertSame('2026-12-01', $subscription->getPaidFrom()->format('Y-m-d'));
        self::assertSame('2027-11-30', $subscription->getPaidUntil()?->format('Y-m-d'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM billing_company_subscriptions WHERE company_id = ?', [self::COMPANY_ID]));
    }

    public function testDatedTariffRequiresValidPeriod(): void
    {
        foreach ([['2027-01-01', null, 'дату окончания'], ['2027-02-01', '2027-01-31', 'позже']] as [$from, $until, $message]) {
            try {
                $this->assign(TariffCode::OUTSOURCER, $from, $until);
                self::fail('invalid period must be rejected');
            } catch (InvalidBillingInputException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM billing_company_subscriptions WHERE company_id = ?', [self::COMPANY_ID]));
        self::assertTrue($this->em->isOpen());
    }

    public function testShorteningPaidPeriodNeedsConfirmation(): void
    {
        $this->payStart('2027-01-15 12:00:00'); // оплачено 15.01–14.02

        try {
            $this->assign(TariffCode::START, '2027-01-15', '2027-01-31');
            self::fail('shortening without confirmation must be rejected');
        } catch (InvalidBillingInputException $exception) {
            self::assertStringContainsString('оплатил по 14.02.2027', $exception->getMessage());
        }
        self::assertSame('2027-02-14', $this->subscription()->getPaidUntil()?->format('Y-m-d'));

        // Не сокращение: окончание не раньше оплаченного, бессрочный тариф.
        $this->assign(TariffCode::SUPPORT, '2027-01-15', '2027-02-14');
        $this->assign(TariffCode::FRIEND, '2027-01-15', null);
        self::assertNull($this->subscription()->getPaidUntil());

        $this->assign(TariffCode::START, '2027-01-15', '2027-01-31', confirmShorten: true);
        self::assertSame('2027-01-31', $this->subscription()->getPaidUntil()?->format('Y-m-d'));

        // Уже снятые подтверждённым сокращением дни флажка повторно не требуют.
        $this->assign(TariffCode::SUPPORT, '2027-01-15', '2027-01-31');
        self::assertSame(TariffCode::SUPPORT, $this->subscription()->getTariff()->getCode());
    }

    public function testPaymentCarriedIntoUpgradeDoesNotCountAsPaidDays(): void
    {
        $this->payStart('2027-01-15 12:00:00', months: 12, amount: 4771000); // 15.01.2027–14.01.2028
        $this->pay(TariffCode::PRO, '2027-01-20 12:00:00', 1, 1479000); // остаток «Старта» перенесён в дни «Профи»
        $paidUntil = $this->subscription()->getPaidUntil()?->format('Y-m-d');
        self::assertNotNull($paidUntil);
        self::assertLessThan('2027-12-31', $paidUntil);

        // Продление «Профи» до 31.12.2027 не сокращает: «по» перенесённой оплаты «Старта» не в счёт.
        $this->assign(TariffCode::PRO, '2027-01-20', '2027-12-31');
        self::assertSame('2027-12-31', $this->subscription()->getPaidUntil()?->format('Y-m-d'));
    }

    public function testFacadeReturnsAccessForSeveralCompaniesAtOnce(): void
    {
        $this->assign(TariffCode::SUPPORT, '2027-01-01', '2027-01-31');
        $missing = '0193bbbb-0000-7000-8000-0000000000ff';

        $access = self::getContainer()->get(BillingFacadeInterface::class)->accessForCompanies(
            [self::COMPANY_ID, self::OTHER_COMPANY_ID, $missing],
            new \DateTimeImmutable('2027-01-20'),
        );

        self::assertSame([self::COMPANY_ID, self::OTHER_COMPANY_ID, $missing], array_keys($access));
        self::assertSame(AccessStatus::ACTIVE, $access[self::COMPANY_ID]->status);
        self::assertSame('На сопровождении', $access[self::COMPANY_ID]->tariffName);
        self::assertSame(AccessStatus::NONE, $access[self::OTHER_COMPANY_ID]->status);
        self::assertSame(AccessStatus::NONE, $access[$missing]->status);
    }

    private function assign(TariffCode $code, string $from, ?string $until, bool $confirmShorten = false): void
    {
        $zone = new \DateTimeZone('Europe/Moscow');
        self::getContainer()->get(AssignTariffAction::class)(new AssignTariffCommand(
            self::COMPANY_ID,
            $code,
            new \DateTimeImmutable($from, $zone),
            null === $until ? null : new \DateTimeImmutable($until, $zone),
            $confirmShorten,
        ));
    }

    private function payStart(string $receivedAt, int $months = 1, int $amount = 497000): void
    {
        $this->pay(TariffCode::START, $receivedAt, $months, $amount);
    }

    private function pay(TariffCode $tariff, string $receivedAt, int $months, int $amount): void
    {
        self::getContainer()->get(RegisterPaymentAction::class)(new RegisterPaymentCommand(
            Uuid::uuid7()->toString(),
            self::COMPANY_ID,
            $tariff,
            $months,
            $amount,
            new \DateTimeImmutable($receivedAt, new \DateTimeZone('Europe/Moscow')),
            PaymentSource::MANUAL_INVOICE,
        ));
    }

    private function subscription(): CompanySubscription
    {
        $this->em->clear();
        $subscription = $this->em->getRepository(CompanySubscription::class)->findOneBy(['companyId' => self::COMPANY_ID]);
        self::assertNotNull($subscription);

        return $subscription;
    }
}
