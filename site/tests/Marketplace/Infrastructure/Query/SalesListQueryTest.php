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
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class SalesListQueryTest extends IntegrationTestCase
{
    private Company $company;
    private MarketplaceListing $wbListing;
    private MarketplaceListing $ozonListing;
    private SalesListQuery $salesListQuery;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000077')
            ->withEmail('sales-list-query@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-000000000077')
            ->withOwner($owner)
            ->build();

        $this->wbListing = new MarketplaceListing(
            Uuid::uuid4()->toString(),
            $this->company,
            null,
            MarketplaceType::WILDBERRIES,
        );
        $this->wbListing->setMarketplaceSku('wb-sku-1');
        $this->wbListing->setPrice('1000.00');

        $this->ozonListing = new MarketplaceListing(
            Uuid::uuid4()->toString(),
            $this->company,
            null,
            MarketplaceType::OZON,
        );
        $this->ozonListing->setMarketplaceSku('ozon-sku-1');
        $this->ozonListing->setPrice('1000.00');

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->persist($this->wbListing);
        $this->em->persist($this->ozonListing);
        $this->em->flush();

        $this->salesListQuery = self::getContainer()->get(SalesListQuery::class);
    }

    public function testDoesNotFilterWhenDatesAreNull(): void
    {
        $this->createSale($this->wbListing, MarketplaceType::WILDBERRIES, new \DateTimeImmutable('2026-04-01'));
        $this->createSale($this->wbListing, MarketplaceType::WILDBERRIES, new \DateTimeImmutable('2026-04-15'));
        $this->createSale($this->wbListing, MarketplaceType::WILDBERRIES, new \DateTimeImmutable('2026-04-30'));
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
        $this->createSale($this->wbListing, MarketplaceType::WILDBERRIES, new \DateTimeImmutable('2026-04-01'));
        $this->createSale($this->wbListing, MarketplaceType::WILDBERRIES, new \DateTimeImmutable('2026-04-15'));
        $this->createSale($this->wbListing, MarketplaceType::WILDBERRIES, new \DateTimeImmutable('2026-04-30'));
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
        $this->createSale($this->wbListing, MarketplaceType::WILDBERRIES, new \DateTimeImmutable('2026-04-01'));
        $this->createSale($this->wbListing, MarketplaceType::WILDBERRIES, new \DateTimeImmutable('2026-04-15'));
        $this->createSale($this->wbListing, MarketplaceType::WILDBERRIES, new \DateTimeImmutable('2026-04-30'));
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
        $this->createSale($this->wbListing, MarketplaceType::WILDBERRIES, new \DateTimeImmutable('2026-04-01'));
        $this->createSale($this->wbListing, MarketplaceType::WILDBERRIES, new \DateTimeImmutable('2026-04-20'));
        $this->createSale($this->ozonListing, MarketplaceType::OZON, new \DateTimeImmutable('2026-04-10'));
        $this->createSale($this->ozonListing, MarketplaceType::OZON, new \DateTimeImmutable('2026-04-25'));
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
        MarketplaceType $marketplace,
        \DateTimeImmutable $saleDate,
    ): MarketplaceSale {
        $sale = new MarketplaceSale(
            Uuid::uuid4()->toString(),
            $this->company,
            $listing,
            $marketplace,
        );
        $sale->setExternalOrderId('ext-' . Uuid::uuid4()->toString());
        $sale->setSaleDate($saleDate);
        $sale->setQuantity(1);
        $sale->setPricePerUnit('1000.00');
        $sale->setTotalRevenue('1000.00');
        $this->em->persist($sale);

        return $sale;
    }

    private static function normalizeDate(string $dbValue): string
    {
        return (new \DateTimeImmutable($dbValue))->format('Y-m-d');
    }
}
