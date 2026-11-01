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
use App\Tests\Support\Billing\TariffSeed;
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

    /**
     * Справочник в БД (после миграции или DbReset) совпадает с копией для DbReset.
     */
    public function testSeededTariffsMatchMigration(): void
    {
        $tariffs = $this->connection->fetchAllAssociativeIndexed('SELECT id, code, name, is_public, is_perpetual, is_active, sort_order FROM billing_tariffs ORDER BY id');
        $expected = [];
        foreach (TariffSeed::tariffs() as $id => $t) {
            $expected[$id] = ['code' => $t['code'], 'name' => $t['name'], 'is_public' => $t['public'], 'is_perpetual' => $t['perpetual'], 'is_active' => true, 'sort_order' => $t['sortOrder']];
        }
        ksort($expected);
        self::assertSame($expected, $tariffs);

        $prices = $this->connection->fetchAllAssociativeIndexed('SELECT id, tariff_id, months, amount_kopecks FROM billing_tariff_prices ORDER BY id');
        $expectedPrices = [];
        foreach (TariffSeed::prices() as $id => $p) {
            $expectedPrices[$id] = ['tariff_id' => $p['tariffId'], 'months' => $p['months'], 'amount_kopecks' => $p['amountKopecks']];
        }
        ksort($expectedPrices);
        self::assertSame($expectedPrices, $prices);
        self::assertSame(14198000, $expectedPrices['01a18a1b-3000-7000-8000-00000000014c']['amount_kopecks'], 'Профи, 12 мес = 141 980 ₽');
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
