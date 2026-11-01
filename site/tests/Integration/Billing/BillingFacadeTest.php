<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Enum\AccessStatus;
use App\Billing\Enum\TariffCode;
use App\Billing\Facade\BillingFacadeInterface;
use App\Billing\Repository\TariffRepository;
use App\Tests\Builders\Billing\CompanySubscriptionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class BillingFacadeTest extends IntegrationTestCase
{
    private const COMPANY_ID = '0193bbbb-0000-7000-8000-0000000000f1';

    protected function setUp(): void
    {
        parent::setUp();
        $owner = UserBuilder::aUser()->build();
        $this->em->persist($owner);
        $this->em->persist(CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build());
        $this->em->flush();
    }

    public function testCompanyWithoutSubscription(): void
    {
        $access = $this->facade()->accessFor(self::COMPANY_ID, new \DateTimeImmutable('2027-01-10'));

        self::assertSame(AccessStatus::NONE, $access->status);
        self::assertNull($access->tariffCode);
        self::assertNull($access->paidUntil);
        self::assertNull($access->daysLeft);
    }

    public function testPaidSubscriptionThroughAllStatuses(): void
    {
        $this->persistSubscription(TariffCode::BASE, '2027-01-01', '2027-01-31');

        $active = $this->facade()->accessFor(self::COMPANY_ID, new \DateTimeImmutable('2027-01-25 12:00'));
        self::assertSame(AccessStatus::ACTIVE, $active->status);
        self::assertSame(TariffCode::BASE, $active->tariffCode);
        self::assertSame('База', $active->tariffName);
        self::assertSame('2027-01-31', $active->paidUntil?->format('Y-m-d'));
        self::assertSame(7, $active->daysLeft);

        self::assertSame(AccessStatus::GRACE, $this->facade()->accessFor(self::COMPANY_ID, new \DateTimeImmutable('2027-02-03'))->status);
        self::assertSame(AccessStatus::READ_ONLY, $this->facade()->accessFor(self::COMPANY_ID, new \DateTimeImmutable('2027-02-06'))->status);
    }

    public function testPerpetualTariff(): void
    {
        $this->persistSubscription(TariffCode::FRIEND, '2026-11-01', null);

        $access = $this->facade()->accessFor(self::COMPANY_ID, new \DateTimeImmutable('2035-06-01'));
        self::assertSame(AccessStatus::ACTIVE, $access->status);
        self::assertSame('Друг', $access->tariffName);
        self::assertNull($access->paidUntil);
        self::assertNull($access->daysLeft);
    }

    public function testSeededTariffsAndPrices(): void
    {
        $prices = $this->connection->fetchAllKeyValue(
            "SELECT t.code || ':' || p.months, p.amount_kopecks FROM billing_tariff_prices p JOIN billing_tariffs t ON t.id = p.tariff_id ORDER BY 1",
        );

        self::assertSame(6, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM billing_tariffs'));
        self::assertCount(12, $prices);
        self::assertSame(497000, $prices['start:1']);
        self::assertSame(995000, $prices['base:1']);
        self::assertSame(1479000, $prices['pro:1']);
        self::assertSame(14198000, $prices['pro:12']);
    }

    private function persistSubscription(TariffCode $code, string $from, ?string $until): void
    {
        $tariff = self::getContainer()->get(TariffRepository::class)->findOneByCode($code);
        self::assertNotNull($tariff);
        $builder = CompanySubscriptionBuilder::aSubscription()->forCompany(self::COMPANY_ID)->onTariff($tariff);
        $this->em->persist(null === $until ? $builder->perpetualFrom($from)->build() : $builder->paid($from, $until)->build());
        $this->em->flush();
        $this->em->clear();
    }

    private function facade(): BillingFacadeInterface
    {
        return self::getContainer()->get(BillingFacadeInterface::class);
    }
}
