<?php

declare(strict_types=1);

namespace App\Tests\MarketplaceAnalytics\Domain\Service;

use App\Marketplace\DTO\AdvertisingCostDTO;
use App\Marketplace\DTO\CostData;
use App\Marketplace\DTO\SaleData;
use App\Marketplace\Enum\AdvertisingType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAnalytics\Domain\Service\CostMappingResolver;
use App\MarketplaceAnalytics\Domain\Service\SnapshotCalculationPolicy;
use App\MarketplaceAnalytics\Domain\ValueObject\CostBreakdown;
use App\MarketplaceAnalytics\Entity\ListingDailySnapshot;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Repository\ListingDailySnapshotRepositoryInterface;
use App\Tests\Builders\MarketplaceAnalytics\ListingDailySnapshotBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SnapshotCalculationPolicyTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const LISTING_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    private MarketplaceFacade&MockObject $marketplaceFacade;
    private CostMappingResolver&MockObject $costMappingResolver;
    private ListingDailySnapshotRepositoryInterface&MockObject $snapshotRepository;
    private SnapshotCalculationPolicy $policy;
    private \DateTimeImmutable $date;

    protected function setUp(): void
    {
        $this->marketplaceFacade = $this->createMock(MarketplaceFacade::class);
        $this->costMappingResolver = $this->createMock(CostMappingResolver::class);
        $this->snapshotRepository = $this->createMock(ListingDailySnapshotRepositoryInterface::class);

        $this->policy = new SnapshotCalculationPolicy(
            $this->marketplaceFacade,
            $this->costMappingResolver,
            $this->snapshotRepository,
        );

        $this->date = new \DateTimeImmutable('2026-01-15');
    }

    public function testCreatesNewSnapshotWhenNotExists(): void
    {
        $this->configureFacadeWithMinimalSale();

        $this->snapshotRepository->method('findOneByUniqueKey')
            ->with(self::COMPANY_ID, self::LISTING_ID, $this->date)
            ->willReturn(null);

        $this->snapshotRepository->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(ListingDailySnapshot::class));

        $snapshot = $this->policy->calculateForListingDay(self::COMPANY_ID, self::LISTING_ID, $this->date);

        $this->assertSame(self::COMPANY_ID, $snapshot->getCompanyId());
        $this->assertSame(self::LISTING_ID, $snapshot->getListingId());
    }

    public function testRecalculatesExistingSnapshot(): void
    {
        $this->configureFacadeWithMinimalSale();

        $existing = ListingDailySnapshotBuilder::aSnapshot()
            ->withListingId(self::LISTING_ID)
            ->build();

        $this->snapshotRepository->method('findOneByUniqueKey')
            ->willReturn($existing);

        $this->snapshotRepository->expects($this->once())
            ->method('save')
            ->with($this->identicalTo($existing));

        $result = $this->policy->calculateForListingDay(self::COMPANY_ID, self::LISTING_ID, $this->date);

        $this->assertSame($existing->getId(), $result->getId());
    }

    public function testAdvertisingCostsFromMarketplaceCostAreExcluded(): void
    {
        $sale = $this->makeMinimalSale();
        $this->marketplaceFacade->method('getSalesForListingAndDate')->willReturn([$sale]);
        $this->marketplaceFacade->method('getReturnsForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getCostPriceForListing')->willReturn('100.00');
        $this->marketplaceFacade->method('getOrdersForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getAdvertisingCostsForListingAndDate')->willReturn([]);

        $logisticsCost = new CostData(
            marketplace: MarketplaceType::WILDBERRIES,
            categoryCode: 'logistics_delivery',
            amount: '50.00',
            costDate: $this->date,
        );
        $advertisingCost = new CostData(
            marketplace: MarketplaceType::WILDBERRIES,
            categoryCode: 'advertising_cpc',
            amount: '30.00',
            costDate: $this->date,
        );

        $this->marketplaceFacade->method('getCostsForListingAndDate')
            ->willReturn([$logisticsCost, $advertisingCost]);

        $this->costMappingResolver->method('resolve')
            ->willReturnCallback(static fn(string $cid, string $mp, string $code): UnitEconomyCostType => match ($code) {
                'logistics_delivery' => UnitEconomyCostType::LOGISTICS_TO,
                'advertising_cpc' => UnitEconomyCostType::ADVERTISING_CPC,
                default => UnitEconomyCostType::OTHER,
            });

        $this->snapshotRepository->method('findOneByUniqueKey')->willReturn(null);

        $snapshot = $this->policy->calculateForListingDay(self::COMPANY_ID, self::LISTING_ID, $this->date);

        $cb = CostBreakdown::fromArray($snapshot->getCostBreakdown());
        $this->assertSame('50.00', $cb->logisticsTo);
        $this->assertSame('0.00', $cb->storage);
        $this->assertSame('0.00', $cb->commission);
    }

    public function testAdvertisingDetailsFilledFromAdvertisingCostDTO(): void
    {
        $sale = $this->makeMinimalSale();
        $advDto = new AdvertisingCostDTO(
            id: 'adv-1',
            companyId: self::COMPANY_ID,
            listingId: self::LISTING_ID,
            marketplace: MarketplaceType::WILDBERRIES,
            date: $this->date,
            advertisingType: AdvertisingType::CPC,
            amount: '75.50',
            analyticsData: [
                'impressions' => 1000,
                'clicks' => 50,
                'orders' => 5,
                'revenue' => '250.00',
            ],
            externalCampaignId: 'camp-1',
        );

        $this->marketplaceFacade->method('getSalesForListingAndDate')->willReturn([$sale]);
        $this->marketplaceFacade->method('getReturnsForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getCostPriceForListing')->willReturn('100.00');
        $this->marketplaceFacade->method('getCostsForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getOrdersForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getAdvertisingCostsForListingAndDate')->willReturn([$advDto]);

        $this->snapshotRepository->method('findOneByUniqueKey')->willReturn(null);

        $snapshot = $this->policy->calculateForListingDay(self::COMPANY_ID, self::LISTING_ID, $this->date);

        $ad = $snapshot->getAdvertisingDetails();
        $this->assertSame('75.50', $ad['cpc']['spend']);
    }

    public function testCostPriceMissingFlagSetWhenNoCostPrice(): void
    {
        $sale = $this->makeMinimalSale();
        $this->marketplaceFacade->method('getSalesForListingAndDate')->willReturn([$sale]);
        $this->marketplaceFacade->method('getReturnsForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getCostPriceForListing')->willReturn(null);
        $this->marketplaceFacade->method('getCostsForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getOrdersForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getAdvertisingCostsForListingAndDate')->willReturn([]);

        $this->snapshotRepository->method('findOneByUniqueKey')->willReturn(null);

        $snapshot = $this->policy->calculateForListingDay(self::COMPANY_ID, self::LISTING_ID, $this->date);

        $this->assertContains('cost_price_missing', $snapshot->getDataQuality());
    }

    public function testApiAdvertisingMissingFlagWhenNoAdsData(): void
    {
        $sale = $this->makeMinimalSale();
        $this->marketplaceFacade->method('getSalesForListingAndDate')->willReturn([$sale]);
        $this->marketplaceFacade->method('getReturnsForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getCostPriceForListing')->willReturn('100.00');
        $this->marketplaceFacade->method('getCostsForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getOrdersForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getAdvertisingCostsForListingAndDate')->willReturn([]);

        $this->snapshotRepository->method('findOneByUniqueKey')->willReturn(null);

        $snapshot = $this->policy->calculateForListingDay(self::COMPANY_ID, self::LISTING_ID, $this->date);

        $this->assertContains('api_advertising_missing', $snapshot->getDataQuality());
    }

    public function testTotalCostPriceCalculation(): void
    {
        $sale1 = new SaleData(
            marketplace: MarketplaceType::WILDBERRIES,
            externalOrderId: 'ord-1',
            saleDate: $this->date,
            marketplaceSku: 'SKU-1',
            quantity: 2,
            pricePerUnit: '500.00',
            totalRevenue: '1000.00',
        );
        $sale2 = new SaleData(
            marketplace: MarketplaceType::WILDBERRIES,
            externalOrderId: 'ord-2',
            saleDate: $this->date,
            marketplaceSku: 'SKU-1',
            quantity: 1,
            pricePerUnit: '500.00',
            totalRevenue: '500.00',
        );

        $this->marketplaceFacade->method('getSalesForListingAndDate')->willReturn([$sale1, $sale2]);
        $this->marketplaceFacade->method('getReturnsForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getCostPriceForListing')->willReturn('100.00');
        $this->marketplaceFacade->method('getCostsForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getOrdersForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getAdvertisingCostsForListingAndDate')->willReturn([]);

        $this->snapshotRepository->method('findOneByUniqueKey')->willReturn(null);

        $snapshot = $this->policy->calculateForListingDay(self::COMPANY_ID, self::LISTING_ID, $this->date);

        // costPrice=100.00, salesQuantity=3 → totalCostPrice=300.00
        $this->assertSame('300.00', $snapshot->getTotalCostPrice());
        $this->assertSame(3, $snapshot->getSalesQuantity());
    }

    public function testAdvertisingSpendConsistency(): void
    {
        $sale = $this->makeMinimalSale();
        $advDto = new AdvertisingCostDTO(
            id: 'adv-1',
            companyId: self::COMPANY_ID,
            listingId: self::LISTING_ID,
            marketplace: MarketplaceType::WILDBERRIES,
            date: $this->date,
            advertisingType: AdvertisingType::CPC,
            amount: '45.00',
            analyticsData: [
                'impressions' => 500,
                'clicks' => 25,
                'orders' => 3,
                'revenue' => '150.00',
            ],
            externalCampaignId: 'camp-1',
        );

        $this->marketplaceFacade->method('getSalesForListingAndDate')->willReturn([$sale]);
        $this->marketplaceFacade->method('getReturnsForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getCostPriceForListing')->willReturn('100.00');
        $this->marketplaceFacade->method('getCostsForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getOrdersForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getAdvertisingCostsForListingAndDate')->willReturn([$advDto]);

        $this->snapshotRepository->method('findOneByUniqueKey')->willReturn(null);

        $snapshot = $this->policy->calculateForListingDay(self::COMPANY_ID, self::LISTING_ID, $this->date);

        $ad = $snapshot->getAdvertisingDetails();
        $cb = CostBreakdown::fromArray($snapshot->getCostBreakdown());

        // cpcMetrics.spend === costBreakdown.advertising_cpc
        $this->assertSame($ad['cpc']['spend'], $cb->advertisingCpc);
    }

    private function makeMinimalSale(): SaleData
    {
        return new SaleData(
            marketplace: MarketplaceType::WILDBERRIES,
            externalOrderId: 'ord-1',
            saleDate: $this->date,
            marketplaceSku: 'SKU-1',
            quantity: 1,
            pricePerUnit: '500.00',
            totalRevenue: '500.00',
        );
    }

    private function configureFacadeWithMinimalSale(): void
    {
        $sale = $this->makeMinimalSale();

        $this->marketplaceFacade->method('getSalesForListingAndDate')->willReturn([$sale]);
        $this->marketplaceFacade->method('getReturnsForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getCostPriceForListing')->willReturn('100.00');
        $this->marketplaceFacade->method('getCostsForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getOrdersForListingAndDate')->willReturn([]);
        $this->marketplaceFacade->method('getAdvertisingCostsForListingAndDate')->willReturn([]);
    }
}
