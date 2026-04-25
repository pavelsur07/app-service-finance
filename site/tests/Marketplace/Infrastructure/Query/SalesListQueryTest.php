<?php

declare(strict_types=1);

namespace App\Tests\Marketplace\Infrastructure\Query;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\SalesListQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class SalesListQueryTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000077';
    private const OWNER_ID = '22222222-2222-2222-2222-000000000077';

    private Company $company;
    private MarketplaceListing $wbListing;
    private MarketplaceListing $ozonListing;
    private SalesListQuery $salesListQuery;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('sales-list-query@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $this->wbListing = MarketplaceListingBuilder::aListing()
            ->forCompany($this->company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withMarketplaceSku('wb-sku-1')
            ->build();

        $this->ozonListing = MarketplaceListingBuilder::aListing()
            ->forCompany($this->company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('ozon-sku-1')
            ->build();

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->persist($this->wbListing);
        $this->em->persist($this->ozonListing);
        $this->em->flush();

        $this->salesListQuery = self::getContainer()->get(SalesListQuery::class);
    }

    public function testDoesNotFilterWhenDatesAreNull(): void
    {
        foreach (['2026-04-01', '2026-04-15', '2026-04-30'] as $date) {
            $this->createSale($this->wbListing, new \DateTimeImmutable($date));
        }
        $this->em->flush();

        $qb = $this->salesListQuery->buildQueryBuilder(
            companyId: $this->company->getId(),
            marketplace: null,
        );

        $rows = $qb->executeQuery()->fetchAllAssociative();

        self::assertCount(3, $rows);
    }

    public function testFiltersByDateFromInclusive(): void
    {
        foreach (['2026-04-01', '2026-04-15', '2026-04-30'] as $date) {
            $this->createSale($this->wbListing, new \DateTimeImmutable($date));
        }
        $this->em->flush();

        $qb = $this->salesListQuery->buildQueryBuilder(
            companyId: $this->company->getId(),
            marketplace: null,
            from: new \DateTimeImmutable('2026-04-15'),
        );

        $rows = $qb->executeQuery()->fetchAllAssociative();

        self::assertCount(2, $rows);
        $dates = array_map(static fn (array $row): string => self::normalizeDate($row['sale_date']), $rows);
        sort($dates);
        self::assertSame(['2026-04-15', '2026-04-30'], $dates);
    }

    public function testFiltersByDateToInclusive(): void
    {
        foreach (['2026-04-01', '2026-04-15', '2026-04-30'] as $date) {
            $this->createSale($this->wbListing, new \DateTimeImmutable($date));
        }
        $this->em->flush();

        $qb = $this->salesListQuery->buildQueryBuilder(
            companyId: $this->company->getId(),
            marketplace: null,
            to: new \DateTimeImmutable('2026-04-15'),
        );

        $rows = $qb->executeQuery()->fetchAllAssociative();

        self::assertCount(2, $rows);
        $dates = array_map(static fn (array $row): string => self::normalizeDate($row['sale_date']), $rows);
        sort($dates);
        self::assertSame(['2026-04-01', '2026-04-15'], $dates);
    }

    public function testFiltersByDateRangeAndMarketplaceCombined(): void
    {
        $this->createSale($this->wbListing, new \DateTimeImmutable('2026-04-01'));
        $this->createSale($this->wbListing, new \DateTimeImmutable('2026-04-20'));
        $this->createSale($this->ozonListing, new \DateTimeImmutable('2026-04-10'));
        $this->createSale($this->ozonListing, new \DateTimeImmutable('2026-04-25'));
        $this->em->flush();

        $qb = $this->salesListQuery->buildQueryBuilder(
            companyId: $this->company->getId(),
            marketplace: MarketplaceType::WILDBERRIES->value,
            from: new \DateTimeImmutable('2026-04-10'),
            to: new \DateTimeImmutable('2026-04-20'),
        );

        $rows = $qb->executeQuery()->fetchAllAssociative();

        self::assertCount(1, $rows);
        self::assertSame('2026-04-20', self::normalizeDate($rows[0]['sale_date']));
        self::assertSame(MarketplaceType::WILDBERRIES->value, $rows[0]['marketplace']);
    }

    private function createSale(
        MarketplaceListing $listing,
        \DateTimeImmutable $saleDate,
    ): MarketplaceSale {
        $sale = MarketplaceSaleBuilder::aSale()
            ->forCompany($this->company)
            ->forListing($listing)
            ->withSaleDate($saleDate)
            ->build();
        $this->em->persist($sale);

        return $sale;
    }

    private static function normalizeDate(string $dbValue): string
    {
        return (new \DateTimeImmutable($dbValue))->format('Y-m-d');
    }
}
