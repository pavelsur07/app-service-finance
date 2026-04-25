<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Query;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdDocument;
use App\MarketplaceAds\Entity\AdDocumentLine;
use App\MarketplaceAds\Infrastructure\Query\AdSpendByListingQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class AdSpendByListingQueryTest extends IntegrationTestCase
{
    private const COMPANY_A_ID = '11111111-1111-1111-1111-000000000001';
    private const COMPANY_B_ID = '11111111-1111-1111-1111-000000000002';
    private const OWNER_A_ID   = '22222222-2222-2222-2222-000000000001';
    private const OWNER_B_ID   = '22222222-2222-2222-2222-000000000002';

    private const LISTING_1_ID = '55555555-5555-5555-5555-000000000001';
    private const LISTING_2_ID = '55555555-5555-5555-5555-000000000002';
    private const LISTING_WB_ID = '55555555-5555-5555-5555-000000000003';
    private const LISTING_B_ID = '55555555-5555-5555-5555-00000000000B';

    private const PERIOD_FROM = '2026-04-01';
    private const PERIOD_TO   = '2026-04-30';

    private AdSpendByListingQuery $query;
    private Company $companyA;
    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = self::getContainer()->get(AdSpendByListingQuery::class);
    }

    public function testHappyPathReturnsAdSpendByListing(): void
    {
        $this->seedCompanies();
        $this->seedListings();

        $this->persistAdDocumentWithLine(
            companyId:   self::COMPANY_A_ID,
            marketplace: MarketplaceType::OZON,
            reportDate:  '2026-04-10',
            campaignId:  'CAMP-L1-A',
            parentSku:   'SKU-001',
            totalCost:   '100.00',
            listingId:   self::LISTING_1_ID,
            lineCost:    '100.00',
        );
        // Второе документ-строка по тому же листингу — суммируется
        $this->persistAdDocumentWithLine(
            companyId:   self::COMPANY_A_ID,
            marketplace: MarketplaceType::OZON,
            reportDate:  '2026-04-15',
            campaignId:  'CAMP-L1-B',
            parentSku:   'SKU-001',
            totalCost:   '50.00',
            listingId:   self::LISTING_1_ID,
            lineCost:    '50.00',
        );
        $this->persistAdDocumentWithLine(
            companyId:   self::COMPANY_A_ID,
            marketplace: MarketplaceType::OZON,
            reportDate:  '2026-04-20',
            campaignId:  'CAMP-L2',
            parentSku:   'SKU-002',
            totalCost:   '70.50',
            listingId:   self::LISTING_2_ID,
            lineCost:    '70.50',
        );
        $this->em->flush();
        $this->em->clear();

        $result = $this->query->getByListingForPeriod(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
        );

        self::assertCount(2, $result);
        self::assertArrayHasKey(self::LISTING_1_ID, $result);
        self::assertArrayHasKey(self::LISTING_2_ID, $result);
        self::assertEqualsWithDelta(150.0, (float) $result[self::LISTING_1_ID], 0.01);
        self::assertEqualsWithDelta(70.5, (float) $result[self::LISTING_2_ID], 0.01);
    }

    public function testMarketplaceFilterReturnsOnlyMatchingMarketplace(): void
    {
        $this->seedCompanies();
        $this->seedListings();

        $this->persistAdDocumentWithLine(
            companyId:   self::COMPANY_A_ID,
            marketplace: MarketplaceType::OZON,
            reportDate:  '2026-04-10',
            campaignId:  'CAMP-OZON',
            parentSku:   'SKU-001',
            totalCost:   '100.00',
            listingId:   self::LISTING_1_ID,
            lineCost:    '100.00',
        );
        $this->persistAdDocumentWithLine(
            companyId:   self::COMPANY_A_ID,
            marketplace: MarketplaceType::WILDBERRIES,
            reportDate:  '2026-04-12',
            campaignId:  'CAMP-WB',
            parentSku:   'WB-001',
            totalCost:   '40.00',
            listingId:   self::LISTING_WB_ID,
            lineCost:    '40.00',
        );
        $this->em->flush();
        $this->em->clear();

        $allMps = $this->query->getByListingForPeriod(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
        );
        self::assertCount(2, $allMps, 'Без фильтра по marketplace — обе записи');

        $onlyOzon = $this->query->getByListingForPeriod(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            MarketplaceType::OZON->value,
        );
        self::assertCount(1, $onlyOzon);
        self::assertArrayHasKey(self::LISTING_1_ID, $onlyOzon);

        $onlyWb = $this->query->getByListingForPeriod(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            MarketplaceType::WILDBERRIES->value,
        );
        self::assertCount(1, $onlyWb);
        self::assertArrayHasKey(self::LISTING_WB_ID, $onlyWb);
    }

    public function testEmptyPeriodReturnsEmptyArray(): void
    {
        $this->seedCompanies();
        $this->seedListings();

        // Запись ВНЕ периода — не должна попасть в результат
        $this->persistAdDocumentWithLine(
            companyId:   self::COMPANY_A_ID,
            marketplace: MarketplaceType::OZON,
            reportDate:  '2026-03-15',
            campaignId:  'CAMP-OUT',
            parentSku:   'SKU-001',
            totalCost:   '100.00',
            listingId:   self::LISTING_1_ID,
            lineCost:    '100.00',
        );
        $this->em->flush();
        $this->em->clear();

        $result = $this->query->getByListingForPeriod(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
        );

        self::assertSame([], $result);
    }

    public function testNonAttributedListingIdIsIncludedInResult(): void
    {
        // В отличие от AdEfficiencyQuery (который inner-join на marketplace_listings),
        // AdSpendByListingQuery НЕ фильтрует по существованию листинга — это критично
        // для согласованности totals на потребителях. Висячий listing_id должен попасть в выдачу.
        $this->seedCompanies();
        $this->seedListings();

        $orphanListingId = '99999999-9999-9999-9999-000000000001';

        $this->persistAdDocumentWithLine(
            companyId:   self::COMPANY_A_ID,
            marketplace: MarketplaceType::OZON,
            reportDate:  '2026-04-10',
            campaignId:  'CAMP-LIVE',
            parentSku:   'SKU-001',
            totalCost:   '100.00',
            listingId:   self::LISTING_1_ID,
            lineCost:    '100.00',
        );
        $this->persistAdDocumentWithLine(
            companyId:   self::COMPANY_A_ID,
            marketplace: MarketplaceType::OZON,
            reportDate:  '2026-04-12',
            campaignId:  'CAMP-ORPHAN',
            parentSku:   'SKU-ORPHAN',
            totalCost:   '42.00',
            listingId:   $orphanListingId,
            lineCost:    '42.00',
        );
        $this->em->flush();
        $this->em->clear();

        $result = $this->query->getByListingForPeriod(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
        );

        self::assertCount(2, $result);
        self::assertArrayHasKey($orphanListingId, $result);
        self::assertEqualsWithDelta(42.0, (float) $result[$orphanListingId], 0.01);
        self::assertEqualsWithDelta(100.0, (float) $result[self::LISTING_1_ID], 0.01);
    }

    public function testIdorOtherCompanyDataIsNotReturned(): void
    {
        $this->seedCompanies();
        $this->seedListings();

        $this->persistAdDocumentWithLine(
            companyId:   self::COMPANY_A_ID,
            marketplace: MarketplaceType::OZON,
            reportDate:  '2026-04-10',
            campaignId:  'CAMP-A',
            parentSku:   'SKU-001',
            totalCost:   '100.00',
            listingId:   self::LISTING_1_ID,
            lineCost:    '100.00',
        );
        $this->persistAdDocumentWithLine(
            companyId:   self::COMPANY_B_ID,
            marketplace: MarketplaceType::OZON,
            reportDate:  '2026-04-15',
            campaignId:  'CAMP-B',
            parentSku:   'SKU-B',
            totalCost:   '999.00',
            listingId:   self::LISTING_B_ID,
            lineCost:    '999.00',
        );
        $this->em->flush();
        $this->em->clear();

        $resultA = $this->query->getByListingForPeriod(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
        );
        self::assertCount(1, $resultA);
        self::assertArrayHasKey(self::LISTING_1_ID, $resultA);
        self::assertArrayNotHasKey(self::LISTING_B_ID, $resultA);

        $resultB = $this->query->getByListingForPeriod(
            self::COMPANY_B_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
        );
        self::assertCount(1, $resultB);
        self::assertArrayHasKey(self::LISTING_B_ID, $resultB);
        self::assertArrayNotHasKey(self::LISTING_1_ID, $resultB);
    }

    private function seedCompanies(): void
    {
        $ownerA = UserBuilder::aUser()
            ->withId(self::OWNER_A_ID)
            ->withEmail('ad-spend-a@example.test')
            ->build();
        $ownerB = UserBuilder::aUser()
            ->withId(self::OWNER_B_ID)
            ->withEmail('ad-spend-b@example.test')
            ->build();

        $this->companyA = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_A_ID)
            ->withOwner($ownerA)
            ->build();
        $this->companyB = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_B_ID)
            ->withOwner($ownerB)
            ->build();

        $this->em->persist($ownerA);
        $this->em->persist($ownerB);
        $this->em->persist($this->companyA);
        $this->em->persist($this->companyB);
        $this->em->flush();
    }

    private function seedListings(): void
    {
        $listing1 = $this->newListing(
            self::LISTING_1_ID,
            $this->companyA,
            MarketplaceType::OZON,
            'SKU-001',
            'Товар 1',
        );
        $listing2 = $this->newListing(
            self::LISTING_2_ID,
            $this->companyA,
            MarketplaceType::OZON,
            'SKU-002',
            'Товар 2',
        );
        $listingWb = $this->newListing(
            self::LISTING_WB_ID,
            $this->companyA,
            MarketplaceType::WILDBERRIES,
            'WB-001',
            'WB товар',
        );
        $listingB = $this->newListing(
            self::LISTING_B_ID,
            $this->companyB,
            MarketplaceType::OZON,
            'SKU-B',
            'Товар компании B',
        );

        foreach ([$listing1, $listing2, $listingWb, $listingB] as $l) {
            $this->em->persist($l);
        }
        $this->em->flush();
    }

    private function newListing(
        string $id,
        Company $company,
        MarketplaceType $marketplace,
        string $sku,
        string $name,
    ): MarketplaceListing {
        $listing = new MarketplaceListing($id, $company, null, $marketplace);
        $listing->setMarketplaceSku($sku);
        $listing->setSize('UNKNOWN');
        $listing->setPrice('0.00');
        $listing->setName($name);

        return $listing;
    }

    private function persistAdDocumentWithLine(
        string $companyId,
        MarketplaceType $marketplace,
        string $reportDate,
        string $campaignId,
        string $parentSku,
        string $totalCost,
        string $listingId,
        string $lineCost,
    ): void {
        $adRawDocumentId = Uuid::uuid4()->toString();

        $adDocument = new AdDocument(
            companyId: $companyId,
            marketplace: $marketplace,
            reportDate: new \DateTimeImmutable($reportDate),
            campaignId: $campaignId,
            campaignName: $campaignId,
            parentSku: $parentSku,
            totalCost: $totalCost,
            totalImpressions: 0,
            totalClicks: 0,
            adRawDocumentId: $adRawDocumentId,
        );
        $this->em->persist($adDocument);

        $line = new AdDocumentLine(
            adDocument: $adDocument,
            listingId: $listingId,
            sharePercent: '100.00',
            cost: $lineCost,
            impressions: 0,
            clicks: 0,
        );
        $this->em->persist($line);
    }
}
