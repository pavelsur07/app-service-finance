<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\Processor\OzonAccrualSalesRawProcessor;
use App\Marketplace\Application\Service\MarketplaceCostPriceResolver;
use App\Marketplace\Application\Service\OzonListingEnsureService;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Inventory\CostPriceResolverInterface;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Продажи из by-day. Ключевые инварианты установлены сверкой с месячным
 * отчётом «Реализация» за июнь 2026: суммы сошлись до копейки.
 */
final class OzonAccrualSalesRawProcessorTest extends TestCase
{
    private const COMPANY_ID = 'company-1';
    private const RAW_DOC_ID = '11111111-1111-4111-8111-111111111111';

    /** @var list<array{externalOrderId: string, quantity: int, pricePerUnit: string, totalRevenue: string}> */
    private array $persisted = [];

    public function testRevenueUsesSellerBasisLikeLegacyRowsInTheSameTable(): void
    {
        // Регрессия. marketplace_sales ведёт базу ПРОДАВЦА: сверка за июнь по
        // кабинету Вумджой сошлась точно — 1 950 549.00 в таблице против суммы
        // sale_amount по продажам 1 950 549 на тех же 750 строках.
        // sale_price (цена покупателя) — база месячной «Реализации», у неё
        // другой потребитель; записать её сюда значило бы сменить базу посреди
        // таблицы и занизить выручку в 2.25 раза.
        $processor = $this->processor();

        $processor->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$this->saleRow()], self::RAW_DOC_ID);

        self::assertCount(1, $this->persisted);
        self::assertSame('2999.00', $this->persisted[0]['totalRevenue']);
        self::assertSame('2999.00', $this->persisted[0]['pricePerUnit']);
        self::assertSame(1, $this->persisted[0]['quantity']);
    }

    public function testQuantityIsDerivedFromSellerBasisNotHardcoded(): void
    {
        $row = $this->saleRow();
        // Одна и та же база: sale_amount = количество x seller_price.
        $row['posting']['products'][0]['commission']['sale_amount']['amount'] = '8997';
        $row['posting']['products'][0]['commission']['seller_price']['amount'] = '2999';

        $this->processor()->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$row], self::RAW_DOC_ID);

        self::assertSame(3, $this->persisted[0]['quantity']);
        self::assertSame('8997.00', $this->persisted[0]['totalRevenue']);
        self::assertSame('2999.00', $this->persisted[0]['pricePerUnit']);
    }

    public function testExternalIdIsStableAcrossRunsSoRepeatIsIdempotent(): void
    {
        $processor = $this->processor();
        $processor->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$this->saleRow()], self::RAW_DOC_ID);
        $first = $this->persisted[0]['externalOrderId'];

        $this->persisted = [];
        $repeat = $this->processor(existingIds: [$first]);
        $repeat->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$this->saleRow()], self::RAW_DOC_ID);

        // Ключ строится на номере отправления, а не на accrual_id: тот же номер
        // несёт возврат этого товара, и по нему он находит исходную продажу.
        self::assertStringContainsString('80000001-1001-1', $first);
        self::assertSame([], $this->persisted, 'Повторный прогон не создаёт вторую продажу.');
    }

    public function testRowWithoutSaleAmountIsSkippedInsteadOfBecomingZeroSale(): void
    {
        $row = $this->saleRow();
        unset($row['posting']['products'][0]['commission']['sale_amount']);

        $this->processor()->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$row], self::RAW_DOC_ID);

        self::assertSame([], $this->persisted);
    }

    public function testNonIntegerQuantityIsSkippedInsteadOfGuessed(): void
    {
        // Нецелое частное означает, что предположение о базе неверно. Тихо
        // округлить — значит подделать финансовую строку.
        $row = $this->saleRow();
        $row['posting']['products'][0]['commission']['sale_amount']['amount'] = '1000';
        $row['posting']['products'][0]['commission']['seller_price']['amount'] = '333';

        $this->processor()->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$row], self::RAW_DOC_ID);

        self::assertSame([], $this->persisted);
    }

    public function testProcessorClaimsOnlyByDayFormat(): void
    {
        $processor = $this->processor();

        self::assertTrue($processor->supports(StagingRecordType::SALE, MarketplaceType::OZON, '', MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY));
        self::assertFalse($processor->supports(StagingRecordType::SALE, MarketplaceType::OZON, '', MarketplaceRawFormat::OZON_TRANSACTION_LIST_V3));
        self::assertFalse($processor->supports(StagingRecordType::SALE, MarketplaceType::OZON, '', null));
        self::assertFalse($processor->supports(StagingRecordType::COST, MarketplaceType::OZON, '', MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY));
    }

    /**
     * @return array<string, mixed>
     */
    private function saleRow(): array
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__.'/../../../../Fixtures/Marketplace/Ozon/accrual_by_day_cases.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $fixture['accruals'][0];
    }

    /**
     * @param list<string> $existingIds
     */
    private function processor(array $existingIds = []): OzonAccrualSalesRawProcessor
    {
        $company = (new \ReflectionClass(Company::class))->newInstanceWithoutConstructor();
        $this->setProperty($company, 'id', self::COMPANY_ID);

        $listing = (new \ReflectionClass(MarketplaceListing::class))->newInstanceWithoutConstructor();
        $this->setProperty($listing, 'id', 'listing-1');
        $this->setProperty($listing, 'company', $company);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($company);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof MarketplaceSale) {
                $this->persisted[] = [
                    'externalOrderId' => $entity->getExternalOrderId(),
                    'quantity' => $entity->getQuantity(),
                    'pricePerUnit' => $entity->getPricePerUnit(),
                    'totalRevenue' => $entity->getTotalRevenue(),
                ];
            }
        });

        // OzonListingEnsureService и MarketplaceCostPriceResolver объявлены
        // final: собираются рефлексией с подменёнными внутренностями — так же,
        // как в тесте легаси-процессора продаж.
        $listings = (new \ReflectionClass(OzonListingEnsureService::class))->newInstanceWithoutConstructor();
        $listingRepository = $this->createMock(MarketplaceListingRepository::class);
        $listingRepository->method('findListingsBySkusIndexed')->willReturnCallback(
            static fn (Company $c, MarketplaceType $m, array $skus): array => array_fill_keys($skus, $listing),
        );
        $this->setProperty($listings, 'listingRepository', $listingRepository);

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->method('getExistingExternalIds')->willReturn(array_fill_keys($existingIds, true));

        $costPrice = (new \ReflectionClass(MarketplaceCostPriceResolver::class))->newInstanceWithoutConstructor();
        $innerResolver = $this->createMock(CostPriceResolverInterface::class);
        $innerResolver->method('resolve')->willReturn('0.00');
        $this->setProperty($costPrice, 'costPriceResolver', $innerResolver);

        return new OzonAccrualSalesRawProcessor(
            $em,
            $listings,
            $saleRepository,
            $costPrice,
            new NullLogger(),
        );
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }
}
