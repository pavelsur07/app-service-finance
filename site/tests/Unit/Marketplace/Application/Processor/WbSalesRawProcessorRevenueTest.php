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
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
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

    public function testUsesRetailPriceWithDiscAsRevenueForNewCamelCase(): void
    {
        $result = $this->runProcessorWithRow([
            'docTypeName' => 'Sale',
            'retailPriceWithDisc' => 2493,
            'retailAmount' => 1607,
            'quantity' => 1,
            'srid' => 'WB-ORDER-1',
            'nmId' => '123',
            'techSize' => 'XL',
            'saleDt' => '2026-01-10 10:00:00',
        ]);

        self::assertCount(1, $result['sales']);
        self::assertSame([['123']], $result['nmIdsCalls']);
        self::assertSame('2493', $result['sales'][0]->getTotalRevenue());
        self::assertSame('2493', $result['sales'][0]->getPricePerUnit());
        self::assertNotSame('1607', $result['sales'][0]->getTotalRevenue());
        self::assertSame('2026-01-10', $result['sales'][0]->getSaleDate()->format('Y-m-d'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{sales: list<MarketplaceSale>, nmIdsCalls: list<list<string>>}
     */
    private function runProcessorWithRow(array $row): array
    {
        $company = $this->createMock(Company::class);
        $listing = $this->createMock(MarketplaceListing::class);

        $persistedSales = [];
        $nmIdsCalls = [];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnMap([
            [Company::class, 'company-1', $company],
        ]);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persistedSales): void {
            if ($entity instanceof MarketplaceSale) {
                $persistedSales[] = $entity;
            }
        });

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->method('getExistingExternalIds')->willReturn([]);

        $listingRepository = $this->createMock(MarketplaceListingRepository::class);
        $listingRepository->method('findListingsByNmIdsIndexed')
            ->willReturnCallback(static function (
                Company $companyArg,
                MarketplaceType $marketplace,
                array $nmIds,
            ) use ($listing, &$nmIdsCalls): array {
                $nmIdsCalls[] = $nmIds;
                return ['123_XL' => $listing];
            });

        $barcodeCatalog = $this->createMock(MarketplaceBarcodeCatalogService::class);
        $costPriceResolver = $this->createMock(MarketplaceCostPriceResolver::class);
        $costPriceResolver->method('resolveForSale')->willReturn(null);

        $processor = new WbSalesRawProcessor(
            $this->createMock(ProcessWbSalesAction::class),
            $em,
            $saleRepository,
            $listingRepository,
            $this->createMock(WbListingResolverService::class),
            $barcodeCatalog,
            $costPriceResolver,
            new WbSalesReportRowNormalizer(),
            new NullLogger(),
        );

        $processor->processBatch('company-1', MarketplaceType::WILDBERRIES, [$row]);

        return ['sales' => $persistedSales, 'nmIdsCalls' => $nmIdsCalls];
    }
}
