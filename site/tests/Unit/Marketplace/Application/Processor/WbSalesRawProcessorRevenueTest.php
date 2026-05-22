<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\ProcessWbSalesAction;
use App\Marketplace\Application\Processor\WbSalesRawProcessor;
use App\Marketplace\Application\Service\MarketplaceBarcodeCatalogService;
use App\Marketplace\Application\Service\MarketplaceCostPriceResolver;
use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use App\Marketplace\Infrastructure\Query\WbBarcodeUpsertQuery;
use App\Marketplace\Inventory\CostPriceResolverInterface;
use App\Marketplace\Repository\MarketplaceBarcodeCatalogRepository;
use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WbSalesRawProcessorRevenueTest extends TestCase
{
    public function testUsesRetailPriceWithDiscAsRevenueForOldSnakeCase(): void
    {
        $result = $this->runProcessorWithRow([
            'doc_type_name' => 'Продажа',
            'retail_price_withdisc_rub' => 2493,
            'retail_amount' => 1607,
            'quantity' => 1,
            'srid' => 'WB-ORDER-1',
            'nm_id' => '123',
            'ts_name' => 'XL',
            'sale_dt' => '2026-01-10 10:00:00',
        ]);

        self::assertCount(1, $result['sales']);
        self::assertSame([['123']], $result['nmIdsCalls']);
        self::assertSame('2493', $result['sales'][0]->getTotalRevenue());
        self::assertSame('2493', $result['sales'][0]->getPricePerUnit());
        self::assertNotSame('1607', $result['sales'][0]->getTotalRevenue());
        self::assertSame('2026-01-10', $result['sales'][0]->getSaleDate()->format('Y-m-d'));
    }

    public function testCamelCaseSaleCreatesSaleAndListingWithNormalizedMetadata(): void
    {
        $result = $this->runProcessorWithRow([
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Продажа',
            'quantity' => 1,
            'retailPriceWithDisc' => 2099,
            'retailAmount' => 1584,
            'nmId' => '123456',
            'techSize' => 'M',
            'sku' => '200000000001',
            'vendorCode' => 'ART-001',
            'brandName' => 'TestBrand',
            'subjectName' => 'Одежда',
            'retailPrice' => 2500,
            'saleDt' => '2026-05-21T10:00:00Z',
            'rrDate' => '2026-05-21',
            'srid' => 'test-sale-srid-1',
        ], true);

        self::assertCount(1, $result['sales']);
        self::assertSame('test-sale-srid-1', $result['sales'][0]->getExternalOrderId());
        self::assertSame('2099', $result['sales'][0]->getTotalRevenue());
        self::assertNotSame('1584', $result['sales'][0]->getTotalRevenue());

        self::assertCount(1, $result['createdListings']);
        $listing = $result['createdListings'][0];
        self::assertSame('ART-001', $listing->getSupplierSku());
        self::assertSame('2500', $listing->getPrice());
        self::assertSame('M', $listing->getSize());
        self::assertNotNull($listing->getName());
        self::assertStringContainsString('TestBrand', (string) $listing->getName());
        self::assertStringContainsString('Одежда', (string) $listing->getName());
        self::assertStringContainsString('ART-001', (string) $listing->getName());
        self::assertStringContainsString('M', (string) $listing->getName());
    }

    /**
     * @param array<string, mixed> $row
     * @return array{sales: list<MarketplaceSale>, createdListings: list<MarketplaceListing>, nmIdsCalls: list<list<string>>}
     */
    private function runProcessorWithRow(array $row, bool $forceResolve = false): array
    {
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $existingListing = $this->createMock(MarketplaceListing::class);

        $persistedSales = [];
        $createdListings = [];
        $nmIdsCalls = [];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnMap([
            [Company::class, 'company-1', $company],
        ]);
        $em->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persistedSales, &$createdListings): void {
                if ($entity instanceof MarketplaceSale) {
                    $persistedSales[] = $entity;
                }
                if ($entity instanceof MarketplaceListing) {
                    $createdListings[] = $entity;
                }
            },
        );

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->method('getExistingExternalIds')->willReturn([]);

        $listingRepository = $this->createMock(MarketplaceListingRepository::class);
        $listingRepository->method('findListingsByNmIdsIndexed')
            ->willReturnCallback(static function (
                Company $companyArg,
                MarketplaceType $marketplace,
                array $nmIds,
            ) use (&$nmIdsCalls, $forceResolve, $existingListing): array {
                $nmIdsCalls[] = $nmIds;

                return $forceResolve ? [] : ['123_XL' => $existingListing];
            });
        $listingRepository->method('findByNmIdAndSize')->willReturn(null);

        $barcodeRepository = $this->createMock(MarketplaceListingBarcodeRepository::class);
        $barcodeRepository->method('findByBarcode')->willReturn(null);

        $connection = $this->createMock(Connection::class);
        $resolver = new WbListingResolverService(
            $listingRepository,
            $barcodeRepository,
            new WbBarcodeUpsertQuery($connection),
            $em,
        );

        $barcodeCatalogRepository = $this->createMock(MarketplaceBarcodeCatalogRepository::class);
        $barcodeCatalogRepository->method('findByBarcode')->willReturn(null);
        $barcodeCatalogRepository->method('findByBarcodesIndexed')->willReturn([]);
        $barcodeCatalog = new MarketplaceBarcodeCatalogService($barcodeCatalogRepository);

        $innerCostPriceResolver = $this->createMock(CostPriceResolverInterface::class);
        $innerCostPriceResolver->method('resolve')->willReturn('0.00');
        $costPriceResolver = new MarketplaceCostPriceResolver($innerCostPriceResolver);

        $processor = new WbSalesRawProcessor(
            $this->makeProcessWbSalesActionStub(),
            $em,
            $saleRepository,
            $listingRepository,
            $resolver,
            $barcodeCatalog,
            $costPriceResolver,
            new WbSalesReportRowNormalizer(),
            new NullLogger(),
        );

        $processor->processBatch('company-1', MarketplaceType::WILDBERRIES, [$row]);

        return [
            'sales' => $persistedSales,
            'createdListings' => $createdListings,
            'nmIdsCalls' => $nmIdsCalls,
        ];
    }

    private function makeProcessWbSalesActionStub(): ProcessWbSalesAction
    {
        return (new \ReflectionClass(ProcessWbSalesAction::class))->newInstanceWithoutConstructor();
    }
}
