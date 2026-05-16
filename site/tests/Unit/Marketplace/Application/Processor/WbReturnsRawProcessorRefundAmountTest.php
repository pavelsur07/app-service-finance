<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\ProcessWbReturnsAction;
use App\Marketplace\Application\Processor\WbReturnsRawProcessor;
use App\Marketplace\Application\Service\MarketplaceBarcodeCatalogService;
use App\Marketplace\Repository\MarketplaceBarcodeCatalogRepository;
use App\Marketplace\Application\Service\MarketplaceCostPriceResolver;
use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WbReturnsRawProcessorRefundAmountTest extends TestCase
{
    public function testUsesRetailPriceWithDiscAsRefundAmountForOldSnakeCase(): void
    {
        $result = $this->runProcessorWithRow([
            'doc_type_name' => 'Возврат',
            'retail_price_withdisc_rub' => 1125,
            'retail_amount' => 720,
            'retail_price' => 1500,
            'quantity' => 1,
            'srid' => 'WB-RETURN-1',
            'nm_id' => '123',
            'ts_name' => 'XL',
            'supplier_oper_name' => 'Возврат покупателем',
            'rr_dt' => '2026-01-10 10:00:00',
        ]);

        self::assertCount(1, $result['returns']);
        self::assertSame([['123']], $result['nmIdsCalls']);
        self::assertSame('1125', $result['returns'][0]->getRefundAmount());
        self::assertNotSame('720', $result['returns'][0]->getRefundAmount());
        self::assertNotSame('1500', $result['returns'][0]->getRefundAmount());
        self::assertSame('Возврат покупателем', $result['returns'][0]->getReturnReason());
        self::assertSame('2026-01-10', $result['returns'][0]->getReturnDate()->format('Y-m-d'));
    }

    public function testUsesRetailPriceWithDiscAsRefundAmountForNewCamelCase(): void
    {
        $result = $this->runProcessorWithRow([
            'docTypeName' => 'Return',
            'retailPriceWithDisc' => 1125,
            'retailAmount' => 720,
            'retailPrice' => 1500,
            'quantity' => 1,
            'srid' => 'WB-RETURN-1',
            'nmId' => '123',
            'techSize' => 'XL',
            'sellerOperName' => 'Customer return',
            'rrDate' => '2026-01-10 10:00:00',
        ]);

        self::assertCount(1, $result['returns']);
        self::assertSame([['123']], $result['nmIdsCalls']);
        self::assertSame('1125', $result['returns'][0]->getRefundAmount());
        self::assertNotSame('720', $result['returns'][0]->getRefundAmount());
        self::assertNotSame('1500', $result['returns'][0]->getRefundAmount());
        self::assertSame('Customer return', $result['returns'][0]->getReturnReason());
        self::assertSame('2026-01-10', $result['returns'][0]->getReturnDate()->format('Y-m-d'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{returns: list<MarketplaceReturn>, nmIdsCalls: list<list<string>>}
     */
    private function runProcessorWithRow(array $row): array
    {
        $company = $this->createMock(Company::class);
        $listing = $this->createMock(MarketplaceListing::class);

        $persistedReturns = [];
        $nmIdsCalls = [];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnMap([
            [Company::class, 'company-1', $company],
        ]);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persistedReturns): void {
            if ($entity instanceof MarketplaceReturn) {
                $persistedReturns[] = $entity;
            }
        });

        $returnRepository = $this->createMock(MarketplaceReturnRepository::class);
        $returnRepository->method('getExistingExternalIds')->willReturn([]);

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

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->method('findByMarketplaceOrder')->willReturn(null);

        $barcodeCatalogRepository = $this->createMock(MarketplaceBarcodeCatalogRepository::class);
        $barcodeCatalogRepository->method('findByBarcode')->willReturn(null);
        $barcodeCatalogRepository->method('findByBarcodesIndexed')->willReturn([]);
        $barcodeCatalog = new MarketplaceBarcodeCatalogService($barcodeCatalogRepository);
        $costPriceResolver = $this->createMock(MarketplaceCostPriceResolver::class);
        $costPriceResolver->method('resolveForReturn')->willReturn(null);

        $processor = new WbReturnsRawProcessor(
            $this->createMock(ProcessWbReturnsAction::class),
            $em,
            $returnRepository,
            $saleRepository,
            $listingRepository,
            $this->createMock(WbListingResolverService::class),
            $barcodeCatalog,
            $costPriceResolver,
            new WbSalesReportRowNormalizer(),
            new NullLogger(),
        );

        $processor->processBatch('company-1', MarketplaceType::WILDBERRIES, [$row]);

        return ['returns' => $persistedReturns, 'nmIdsCalls' => $nmIdsCalls];
    }
}
