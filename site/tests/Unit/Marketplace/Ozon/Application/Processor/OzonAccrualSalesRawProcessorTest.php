<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Ozon\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\MarketplaceCostPriceResolver;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Inventory\CostPriceResolverInterface;
use App\Marketplace\Ozon\Application\Processor\OzonAccrualSalesRawProcessor;
use App\Marketplace\Ozon\Application\Service\OzonListingEnsureService;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @var list<array{externalOrderId: string, quantity: int, pricePerUnit: string, totalRevenue: string, rawAccrualId: mixed}> */
    private array $persisted = [];

    /** @var list<array{string, string}> */
    private array $claimed = [];

    public function testRevenueUsesSellerBasisLikeLegacyRowsInTheSameTable(): void
    {
        // Регрессия. marketplace_sales ведёт базу ПРОДАВЦА: сверка за июнь по
        // кабинету сошлась точно — 1 950 549.00 в таблице против суммы
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

    public function testAnotherAccrualOfTheSameSaleOnTheSameDayIsAnIndependentSale(): void
    {
        $first = $this->saleRow();
        $second = $this->saleRow();
        $second['accrual_id'] = 50000000099;

        $this->processor()->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$first, $second], self::RAW_DOC_ID);

        // Одно отправление, один товар, один день, два разных начисления — две продажи, а не одна.
        self::assertSame(
            ['ozon-accrual-80000001-1001-1-product-0', 'ozon-accrual-80000001-1001-1-product-0-acc50000000099'],
            array_column($this->persisted, 'externalOrderId'),
        );
    }

    public function testRerunningTheSameBatchWithTwoAccrualsStaysIdempotent(): void
    {
        $first = $this->saleRow();
        $second = $this->saleRow();
        $second['accrual_id'] = 50000000099;
        $base = 'ozon-accrual-80000001-1001-1-product-0';

        $this->processor(stamps: [
            $base => ['accrualId' => '50000000001', 'date' => '2026-06-01', 'amount' => '2999.00'],
            $base.'-acc50000000099' => ['accrualId' => '50000000099', 'date' => '2026-06-01', 'amount' => '2999.00'],
        ])->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$first, $second], self::RAW_DOC_ID);

        self::assertSame([], $this->persisted);
    }

    public function testHistoricalRecordOfTheSameDayAndAmountIsClaimedNotDuplicated(): void
    {
        $base = 'ozon-accrual-80000001-1001-1-product-0';

        $this->processor(stamps: [$base => ['accrualId' => null, 'date' => '2026-06-01', 'amount' => '2999.00']])
            ->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$this->saleRow()], self::RAW_DOC_ID);

        // Историческая запись без метки принадлежит этому начислению: ей ставится метка, новая запись не создаётся.
        self::assertSame([], $this->persisted);
        self::assertSame([[$base, '50000000001']], $this->claimed);
    }

    public function testHistoricalRecordWithDifferentAmountMeansAnotherAccrualOfTheSameDay(): void
    {
        $base = 'ozon-accrual-80000001-1001-1-product-0';

        $this->processor(stamps: [$base => ['accrualId' => null, 'date' => '2026-06-01', 'amount' => '1500.00']])
            ->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$this->saleRow()], self::RAW_DOC_ID);

        self::assertSame([], $this->claimed);
        self::assertSame([$base.'-acc50000000001'], array_column($this->persisted, 'externalOrderId'));
    }

    public function testTwinAccrualsOverOneHistoricalRowKeepBothSales(): void
    {
        $first = $this->saleRow();
        $second = $this->saleRow();
        $second['accrual_id'] = 50000000099;
        $base = 'ozon-accrual-80000001-1001-1-product-0';

        $this->processor(stamps: [$base => ['accrualId' => null, 'date' => '2026-06-01', 'amount' => '2999.00']])
            ->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$first, $second], self::RAW_DOC_ID);

        // Первое начисление забирает историческую запись, второе получает собственную: итого две продажи.
        self::assertSame([[$base, '50000000001']], $this->claimed);
        self::assertSame([$base.'-acc50000000099'], array_column($this->persisted, 'externalOrderId'));
    }

    /**
     * Регрессия по Сухоносову, 01.10.2026: продажа уже записана днём раньше (историческая, без метки, и с меткой
     * другого начисления). Ozon переоформил её (сторно + новое начисление) — новое начисление теряться не должно.
     */
    #[DataProvider('oldAccrualMarkers')]
    public function testRebookedAccrualOfAnotherDayIsRecordedAsSeparateSale(?string $oldAccrual): void
    {
        $rebooked = $this->saleRow();
        $rebooked['accrual_id'] = 50000000099;
        $base = 'ozon-accrual-80000001-1001-1-product-0';

        $this->processor(stamps: [$base => ['accrualId' => $oldAccrual, 'date' => '2026-05-31', 'amount' => '2999.00']])
            ->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$rebooked], self::RAW_DOC_ID);

        self::assertSame([$base.'-acc50000000099'], array_column($this->persisted, 'externalOrderId'));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function oldAccrualMarkers(): iterable
    {
        yield 'историческая запись без метки' => [null];
        yield 'запись с меткой другого начисления' => ['50000000001'];
    }

    public function testSameAccrualOfAnotherDayIsNotRecordedTwice(): void
    {
        $base = 'ozon-accrual-80000001-1001-1-product-0';

        $this->processor(stamps: [$base => ['accrualId' => '50000000001', 'date' => '2026-05-31', 'amount' => '2999.00']])
            ->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$this->saleRow()], self::RAW_DOC_ID);

        self::assertSame([], $this->persisted);
    }

    public function testNewRecordsCarryTheAccrualMarker(): void
    {
        $this->processor()->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$this->saleRow()], self::RAW_DOC_ID);

        self::assertSame('50000000001', $this->persisted[0]['rawAccrualId']);
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

    public function testAmountsThatDoNotMultiplyBackAreSkippedNotRounded(): void
    {
        // Регрессия на допуск по частному. 1000.50 / 1000.00 = 1.0005 — внутри
        // прежнего допуска 0.001, поэтому строка проходила как quantity = 1 и
        // записывала pricePerUnit 1000.00 при totalRevenue 1000.50: строка, где
        // quantity * pricePerUnit не сходится с собственным итогом. Проверка
        // идёт в деньгах, поэтому такая строка теперь пропускается.
        $row = $this->saleRow();
        $row['posting']['products'][0]['commission']['sale_amount']['amount'] = '1000.50';
        $row['posting']['products'][0]['commission']['seller_price']['amount'] = '1000.00';

        $this->processor()->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$row], self::RAW_DOC_ID);

        self::assertSame([], $this->persisted);
    }

    public function testAmountsThatMultiplyBackExactlyArePersisted(): void
    {
        $row = $this->saleRow();
        $row['posting']['products'][0]['commission']['sale_amount']['amount'] = '3000.00';
        $row['posting']['products'][0]['commission']['seller_price']['amount'] = '1000.00';

        $this->processor()->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$row], self::RAW_DOC_ID);

        self::assertCount(1, $this->persisted);
        self::assertSame(3, $this->persisted[0]['quantity']);
        self::assertSame('1000.00', $this->persisted[0]['pricePerUnit']);
        self::assertSame('3000.00', $this->persisted[0]['totalRevenue']);
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
            (string) file_get_contents(__DIR__.'/../../../../../Fixtures/Marketplace/Ozon/accrual_by_day_cases.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $fixture['accruals'][0];
    }

    /**
     * @param list<string> $existingIds ключи записей, созданных этим же начислением (фикстура, 2026-06-01)
     * @param array<string, array{accrualId: ?string, date: string, amount: string}> $stamps явные метки существующих записей
     */
    private function processor(array $existingIds = [], array $stamps = []): OzonAccrualSalesRawProcessor
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
                    'rawAccrualId' => $entity->getRawData()['_accrual_id'] ?? null,
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
        $saleRepository->method('getAccrualStamps')->willReturn($stamps + array_map(
            static fn (): array => ['accrualId' => '50000000001', 'date' => '2026-06-01', 'amount' => '2999.00'],
            array_fill_keys($existingIds, true),
        ));

        $saleRepository->method('claimLegacyRecord')->willReturnCallback(function (string $companyId, string $externalId, string $accrualId): int {
            $this->claimed[] = [$externalId, $accrualId];

            return 1;
        });

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
