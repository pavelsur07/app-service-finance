<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarketplacePlAmountSql;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

final class MarketplacePlAmountSqlTest extends IntegrationTestCase
{
    private Company $company;
    private PLCategory $plCategory;
    private MarketplaceListing $wbListing;
    private MarketplaceListing $ozonListing;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000302')
            ->withEmail('pl-amount-sql@example.test')
            ->build();
        $this->company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-000000000302')
            ->withOwner($owner)
            ->build();
        $this->plCategory = PLCategoryBuilder::aPLCategory()->forCompany($this->company)->withName('Выручка')->build();
        $this->wbListing = MarketplaceListingBuilder::aListing()
            ->forCompany($this->company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withIndex(1)
            ->build();
        $this->ozonListing = MarketplaceListingBuilder::aListing()
            ->forCompany($this->company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withIndex(2)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->persist($this->plCategory);
        $this->em->persist($this->wbListing);
        $this->em->persist($this->ozonListing);

        // Multi-item: у Ozon price_per_unit — начисление за отправление, выручка без СПП = total_revenue.
        $this->createSale($this->wbListing, MarketplaceType::WILDBERRIES, quantity: 3, pricePerUnit: '400.00', totalRevenue: '1100.00', costPrice: '150.00');
        $this->createSale($this->ozonListing, MarketplaceType::OZON, quantity: 3, pricePerUnit: '1000.00', totalRevenue: '1000.00', costPrice: null);
        $this->em->flush();
    }

    /**
     * @return iterable<string, array{AmountSource, ?MarketplaceType, float}>
     */
    public static function saleCases(): iterable
    {
        yield 'gross, WB' => [AmountSource::SALE_GROSS, MarketplaceType::WILDBERRIES, 1200.0];
        yield 'gross, Ozon' => [AmountSource::SALE_GROSS, MarketplaceType::OZON, 1000.0];
        yield 'gross, all marketplaces' => [AmountSource::SALE_GROSS, null, 2200.0];
        yield 'revenue, all marketplaces' => [AmountSource::SALE_REVENUE, null, 2100.0];
        yield 'cost price, NULL counts as zero' => [AmountSource::SALE_COST_PRICE, null, 450.0];
    }

    #[DataProvider('saleCases')]
    public function testSaleAmount(AmountSource $source, ?MarketplaceType $marketplace, float $expected): void
    {
        foreach ([MarketplaceType::WILDBERRIES, MarketplaceType::OZON] as $mappingMarketplace) {
            $this->em->persist(new MarketplaceSaleMapping(
                Uuid::uuid4()->toString(),
                $this->company,
                $mappingMarketplace,
                $source,
                $this->plCategory,
            ));
        }
        $this->em->flush();

        $sql = sprintf(
            'SELECT COALESCE(SUM(%s), 0)
             FROM marketplace_sales s
             INNER JOIN marketplace_sale_mappings m
                 ON m.company_id = s.company_id AND m.marketplace = s.marketplace
             WHERE s.company_id = :companyId %s',
            MarketplacePlAmountSql::saleAmount($marketplace),
            null === $marketplace ? '' : 'AND s.marketplace = :marketplace',
        );
        $params = ['companyId' => $this->company->getId()];
        if (null !== $marketplace) {
            $params['marketplace'] = $marketplace->value;
        }

        self::assertEqualsWithDelta($expected, (float) $this->em->getConnection()->fetchOne($sql, $params), 0.001);
    }

    private function createSale(
        MarketplaceListing $listing,
        MarketplaceType $marketplace,
        int $quantity,
        string $pricePerUnit,
        string $totalRevenue,
        ?string $costPrice,
    ): void {
        $this->em->persist(MarketplaceSaleBuilder::aSale()
            ->forCompany($this->company)
            ->forListing($listing)
            ->withMarketplace($marketplace)
            ->withExternalOrderId('ord-'.Uuid::uuid4()->toString())
            ->withSaleDate(new \DateTimeImmutable('2026-01-10'))
            ->withQuantity($quantity)
            ->withPricePerUnit($pricePerUnit)
            ->withTotalRevenue($totalRevenue)
            ->withCostPrice($costPrice)
            ->build());
    }
}
