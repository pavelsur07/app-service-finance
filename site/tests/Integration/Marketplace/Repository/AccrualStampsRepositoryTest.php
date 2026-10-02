<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class AccrualStampsRepositoryTest extends IntegrationTestCase
{
    private string $companyId;
    private string $otherCompanyId;
    private string $listingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->seedCompany(1);
        $this->otherCompanyId = $this->seedCompany(2);
        $company = $this->em->find(Company::class, $this->companyId);
        self::assertNotNull($company);
        $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('1')->build();
        $this->em->persist($listing);
        $this->em->flush();
        $this->listingId = (string) $listing->getId();
    }

    public function testSaleStampsCarryAccrualIdDateAndLegacyRowsHaveNone(): void
    {
        $this->insertSale('new-key', '2026-09-30', ['sku' => '1', '_accrual_id' => '64793331570']);
        $this->insertSale('legacy-key', '2026-09-29', ['sku' => '1']);
        $this->insertSale('no-raw-key', '2026-09-28', null);
        $this->insertSale('foreign-key', '2026-09-30', ['_accrual_id' => '1'], $this->otherCompanyId);

        $stamps = self::getContainer()->get(MarketplaceSaleRepository::class)->getAccrualStamps($this->companyId, ['new-key', 'legacy-key', 'no-raw-key', 'foreign-key', 'missing']);

        self::assertSame(['accrualId' => '64793331570', 'date' => '2026-09-30', 'amount' => '10.00'], $stamps['new-key']);
        self::assertSame(['accrualId' => null, 'date' => '2026-09-29', 'amount' => '10.00'], $stamps['legacy-key']);
        self::assertSame(['accrualId' => null, 'date' => '2026-09-28', 'amount' => '10.00'], $stamps['no-raw-key']);
        self::assertArrayNotHasKey('foreign-key', $stamps, 'чужая компания не видна');
        self::assertArrayNotHasKey('missing', $stamps);
        self::assertSame([], self::getContainer()->get(MarketplaceSaleRepository::class)->getAccrualStamps($this->companyId, []));
    }

    public function testReturnStampsCarryAccrualIdAndDate(): void
    {
        $this->connection->insert('marketplace_returns', [
            'id' => Uuid::uuid4()->toString(), 'company_id' => $this->companyId, 'listing_id' => $this->listingId, 'marketplace' => 'ozon',
            'external_return_id' => 'ret-key', 'return_date' => '2026-09-27', 'quantity' => 1, 'refund_amount' => '10.00',
            'raw_data' => json_encode(['_accrual_id' => '70000000001'], \JSON_THROW_ON_ERROR), 'created_at' => '2026-09-30 00:00:00', 'updated_at' => '2026-09-30 00:00:00',
        ]);

        $stamps = self::getContainer()->get(MarketplaceReturnRepository::class)->getAccrualStamps($this->companyId, ['ret-key']);

        self::assertSame(['accrualId' => '70000000001', 'date' => '2026-09-27', 'amount' => '10.00'], $stamps['ret-key']);
    }

    public function testClaimingStampsOnlyUnmarkedRowsOfTheCompanyAndKeepsTheFigures(): void
    {
        $this->insertSale('legacy-key', '2026-09-29', ['sku' => '1', 'extra' => 'kept']);
        $this->insertSale('marked-key', '2026-09-29', ['sku' => '1', '_accrual_id' => 'OLD']);
        $this->insertSale('foreign-legacy', '2026-09-29', ['sku' => '1'], $this->otherCompanyId);
        $repository = self::getContainer()->get(MarketplaceSaleRepository::class);

        self::assertSame(1, $repository->claimLegacyRecord($this->companyId, 'legacy-key', 'NEW'));
        self::assertSame(0, $repository->claimLegacyRecord($this->companyId, 'marked-key', 'NEW'), 'уже поставленная метка не перезаписывается');
        self::assertSame(0, $repository->claimLegacyRecord($this->companyId, 'foreign-legacy', 'NEW'), 'чужая компания не затрагивается');
        self::assertSame(0, $repository->claimLegacyRecord($this->companyId, 'foreign-legacy', 'NEW'));

        $row = $this->connection->fetchAssociative("SELECT raw_data::text AS raw, total_revenue, sale_date FROM marketplace_sales WHERE company_id = :c AND external_order_id = 'legacy-key'", ['c' => $this->companyId]);
        self::assertIsArray($row);
        self::assertSame(['sku' => '1', 'extra' => 'kept', '_accrual_id' => 'NEW'], json_decode((string) $row['raw'], true));
        self::assertSame('10.00', $row['total_revenue']);
        self::assertSame('2026-09-29', $row['sale_date']);
        self::assertSame('OLD', json_decode((string) $this->connection->fetchOne("SELECT raw_data::text FROM marketplace_sales WHERE company_id = :c AND external_order_id = 'marked-key'", ['c' => $this->companyId]), true)['_accrual_id']);

        // Запись без raw_data тоже получает метку.
        $this->insertSale('no-raw', '2026-09-28', null);
        self::assertSame(1, $repository->claimLegacyRecord($this->companyId, 'no-raw', 'NEW2'));
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    private function insertSale(string $key, string $date, ?array $raw, ?string $companyId = null): void
    {
        $this->connection->insert('marketplace_sales', [
            'id' => Uuid::uuid4()->toString(), 'company_id' => $companyId ?? $this->companyId, 'listing_id' => $this->listingId, 'marketplace' => 'ozon',
            'external_order_id' => $key, 'sale_date' => $date, 'quantity' => 1, 'price_per_unit' => '10.00', 'total_revenue' => '10.00',
            'raw_data' => null === $raw ? null : json_encode($raw, \JSON_THROW_ON_ERROR), 'created_at' => '2026-09-30 00:00:00', 'updated_at' => '2026-09-30 00:00:00',
        ]);
    }

    private function seedCompany(int $index): string
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();

        return (string) $company->getId();
    }
}
