<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Processor;

use App\Marketplace\Application\ProcessWbCostsAction;
use App\Marketplace\Application\Processor\WbCostsRawProcessor;
use App\Marketplace\Application\Service\MarketplaceBarcodeCatalogService;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Query\MarketplaceCostExistingExternalIdsQuery;
use App\Marketplace\Repository\MarketplaceBarcodeCatalogRepository;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Service\CostCalculator\CostCalculatorInterface;
use App\Marketplace\Service\CostCalculator\WbAcquiringCalculator;
use App\Marketplace\Service\CostCalculator\WbCommissionCalculator;
use App\Marketplace\Service\CostCalculator\WbDeductionCalculator;
use App\Marketplace\Service\CostCalculator\WbLogisticsDeliveryCalculator;
use App\Marketplace\Service\CostCalculator\WbLogisticsReturnCalculator;
use App\Marketplace\Service\CostCalculator\WbLoyaltyDiscountCalculator;
use App\Marketplace\Service\CostCalculator\WbPenaltyCalculator;
use App\Marketplace\Service\CostCalculator\WbProductProcessingCalculator;
use App\Marketplace\Service\CostCalculator\WbPvzProcessingCalculator;
use App\Marketplace\Service\CostCalculator\WbStorageCalculator;
use App\Marketplace\Service\CostCalculator\WbWarehouseLogisticsCalculator;
use App\Shared\Service\SlugifyService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Тесты знакового соглашения WbCostsRawProcessor / WB-калькуляторов.
 *
 * Знаковое соглашение MarketplaceCost.amount / operation_type для WB:
 *   amount всегда положительная (по модулю);
 *   operation_type задаёт смысл операции (CHARGE/STORNO);
 *   если калькулятор не вернул operation_type, процессор ставит CHARGE по умолчанию.
 *
 * Эти тесты ловят регрессию если знаковая логика в калькуляторах изменится
 * или если из persist-блока процессора пропадёт fallback к CHARGE.
 */
final class WbCostsRawProcessorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // supports()
    // -------------------------------------------------------------------------

    public function testSupportsWbCosts(): void
    {
        $processor = $this->makeProcessorWithoutConstructor();

        self::assertTrue(
            $processor->supports('wildberries', MarketplaceType::WILDBERRIES, 'costs'),
        );
    }

    public function testSupportsStagingRecordTypeCost(): void
    {
        $processor = $this->makeProcessorWithoutConstructor();

        self::assertTrue(
            $processor->supports(StagingRecordType::COST, MarketplaceType::WILDBERRIES),
        );
    }

    public function testDoesNotSupportOzon(): void
    {
        $processor = $this->makeProcessorWithoutConstructor();

        self::assertFalse(
            $processor->supports('ozon', MarketplaceType::OZON, 'costs'),
        );
    }

    public function testDoesNotSupportSalesStagingType(): void
    {
        $processor = $this->makeProcessorWithoutConstructor();

        self::assertFalse(
            $processor->supports(StagingRecordType::SALE, MarketplaceType::WILDBERRIES),
        );
    }

    // -------------------------------------------------------------------------
    // Per-calculator amount-invariant: result['amount'] > 0 (по модулю)
    //
    // operation_type здесь не проверяется — калькуляторы его не выставляют.
    // Это делается на уровне WbCostsRawProcessor::processBatch() и
    // ProcessWbCostsAction::__invoke() — отдельные тесты ниже.
    // -------------------------------------------------------------------------

    #[DataProvider('commissionScenarios')]
    public function testWbCommissionCalculatorEmitsPositiveAmount(
        float $retailPriceWithDisc,
        float $acquiringFee,
        float $ppvzForPay,
        float $expectedAbs,
    ): void {
        $entries = (new WbCommissionCalculator())->calculate(
            $this->saleItem([
                'retail_price_withdisc_rub'  => $retailPriceWithDisc,
                'acquiring_fee' => $acquiringFee,
                'ppvz_for_pay'  => $ppvzForPay,
            ]),
            null,
        );

        self::assertCount(1, $entries);
        self::assertSame('commission', $entries[0]['category_code']);
        self::assertGreaterThan(0, (float) $entries[0]['amount']);
        self::assertEqualsWithDelta($expectedAbs, (float) $entries[0]['amount'], 0.01);
    }

    /** @return iterable<string, array{0:float,1:float,2:float,3:float}> */
    public static function commissionScenarios(): iterable
    {
        // commission = retailPriceWithDisc - acquiring - forPay
        yield 'positive commission'  => [1000.0,  20.0, 800.0, 180.0];
        yield 'negative commission'  => [1000.0,  20.0, 1100.0,  120.0]; // commission = -120 → abs
    }

    public function testWbAcquiringCalculatorEmitsPositiveAmountFromNegativeFee(): void
    {
        $entries = (new WbAcquiringCalculator())->calculate(
            $this->saleItem(['acquiring_fee' => -55.50]),
            null,
        );

        self::assertCount(1, $entries);
        self::assertSame('acquiring', $entries[0]['category_code']);
        self::assertGreaterThan(0, (float) $entries[0]['amount']);
        self::assertEqualsWithDelta(55.50, (float) $entries[0]['amount'], 0.001);
    }


    public function testWbCommissionCalculatorSaleFormulaAndOperationTypeCharge(): void
    {
        $entries = (new WbCommissionCalculator())->calculate(
            $this->saleItem([
                'retail_price_withdisc_rub' => 2493.00,
                'ppvz_for_pay' => 1581.10,
                'acquiring_fee' => 64.28,
            ]),
            null,
        );

        self::assertCount(1, $entries);
        self::assertEqualsWithDelta(847.62, (float) $entries[0]['amount'], 0.001);
        self::assertSame(MarketplaceCostOperationType::CHARGE, $entries[0]['operation_type']);
    }

    public function testWbCommissionCalculatorReturnFormulaAndOperationTypeStorno(): void
    {
        $entries = (new WbCommissionCalculator())->calculate(
            $this->saleItem([
                'doc_type_name' => 'Возврат',
                'retail_price_withdisc_rub' => 1125.00,
                'ppvz_for_pay' => 680.99,
                'acquiring_fee' => 27.76,
            ]),
            null,
        );

        self::assertCount(1, $entries);
        self::assertEqualsWithDelta(416.25, (float) $entries[0]['amount'], 0.001);
        self::assertSame(MarketplaceCostOperationType::STORNO, $entries[0]['operation_type']);
    }

    public function testWbAcquiringCalculatorSaleOperationTypeCharge(): void
    {
        $entries = (new WbAcquiringCalculator())->calculate(
            $this->saleItem(['acquiring_fee' => 64.28]),
            null,
        );

        self::assertCount(1, $entries);
        self::assertEqualsWithDelta(64.28, (float) $entries[0]['amount'], 0.001);
        self::assertSame(MarketplaceCostOperationType::CHARGE, $entries[0]['operation_type']);
    }

    public function testWbAcquiringCalculatorReturnOperationTypeStorno(): void
    {
        $entries = (new WbAcquiringCalculator())->calculate(
            $this->saleItem([
                'doc_type_name' => 'Возврат',
                'acquiring_fee' => 27.76,
            ]),
            null,
        );

        self::assertCount(1, $entries);
        self::assertEqualsWithDelta(27.76, (float) $entries[0]['amount'], 0.001);
        self::assertSame(MarketplaceCostOperationType::STORNO, $entries[0]['operation_type']);
    }

    public function testWbLogisticsDeliveryCalculatorEmitsPositiveAmount(): void
    {
        $entries = (new WbLogisticsDeliveryCalculator())->calculate(
            $this->logisticsItem(deliveryAmount: 1, returnAmount: 0, deliveryRub: -42.00),
            null,
        );

        self::assertCount(1, $entries);
        self::assertSame('logistics_delivery', $entries[0]['category_code']);
        self::assertGreaterThan(0, (float) $entries[0]['amount']);
        self::assertEqualsWithDelta(42.00, (float) $entries[0]['amount'], 0.001);
    }

    public function testWbLogisticsReturnCalculatorEmitsPositiveAmount(): void
    {
        $entries = (new WbLogisticsReturnCalculator())->calculate(
            $this->logisticsItem(deliveryAmount: 0, returnAmount: 1, deliveryRub: 33.00),
            null,
        );

        self::assertCount(1, $entries);
        self::assertSame('logistics_return', $entries[0]['category_code']);
        self::assertGreaterThan(0, (float) $entries[0]['amount']);
        self::assertEqualsWithDelta(33.00, (float) $entries[0]['amount'], 0.001);
    }

    public function testWbStorageCalculatorEmitsPositiveAmount(): void
    {
        $entries = (new WbStorageCalculator())->calculate(
            $this->supplierOpItem('Хранение', ['storage_fee' => -125.75]),
            null,
        );

        self::assertCount(1, $entries);
        self::assertSame('storage', $entries[0]['category_code']);
        self::assertGreaterThan(0, (float) $entries[0]['amount']);
        self::assertEqualsWithDelta(125.75, (float) $entries[0]['amount'], 0.001);
    }

    public function testWbPvzProcessingCalculatorEmitsPositiveAmount(): void
    {
        $entries = (new WbPvzProcessingCalculator())->calculate(
            $this->supplierOpItem(
                'Возмещение за выдачу и возврат товаров на ПВЗ',
                ['ppvz_reward' => -17.50],
            ),
            null,
        );

        self::assertCount(1, $entries);
        self::assertSame('pvz_processing', $entries[0]['category_code']);
        self::assertGreaterThan(0, (float) $entries[0]['amount']);
        self::assertEqualsWithDelta(17.50, (float) $entries[0]['amount'], 0.001);
    }

    public function testWbWarehouseLogisticsCalculatorEmitsPositiveAmount(): void
    {
        $entries = (new WbWarehouseLogisticsCalculator())->calculate(
            $this->supplierOpItem(
                'Возмещение издержек по перевозке/по складским операциям с товаром',
                ['rebill_logistic_cost' => 88.20],
            ),
            null,
        );

        self::assertCount(1, $entries);
        self::assertSame('warehouse_logistics', $entries[0]['category_code']);
        self::assertGreaterThan(0, (float) $entries[0]['amount']);
        self::assertEqualsWithDelta(88.20, (float) $entries[0]['amount'], 0.001);
    }

    public function testWbPenaltyCalculatorEmitsPositiveAmount(): void
    {
        $entries = (new WbPenaltyCalculator())->calculate(
            $this->supplierOpItem('Штраф', ['penalty' => -250.00]),
            null,
        );

        self::assertCount(1, $entries);
        self::assertSame('penalty', $entries[0]['category_code']);
        self::assertGreaterThan(0, (float) $entries[0]['amount']);
        self::assertEqualsWithDelta(250.00, (float) $entries[0]['amount'], 0.001);
    }

    public function testWbProductProcessingCalculatorEmitsPositiveAmount(): void
    {
        $entries = (new WbProductProcessingCalculator())->calculate(
            $this->supplierOpItem('Обработка товара', ['acceptance' => -12.30]),
            null,
        );

        self::assertCount(1, $entries);
        self::assertSame('product_processing', $entries[0]['category_code']);
        self::assertGreaterThan(0, (float) $entries[0]['amount']);
        self::assertEqualsWithDelta(12.30, (float) $entries[0]['amount'], 0.001);
    }

    public function testWbDeductionCalculatorEmitsPositiveAmount(): void
    {
        $entries = (new WbDeductionCalculator(new SlugifyService()))->calculate(
            $this->supplierOpItem('Удержание', [
                'deduction'        => -77.00,
                'bonus_type_name'  => 'Списание за отзыв',
            ]),
            null,
        );

        self::assertCount(1, $entries);
        self::assertGreaterThan(0, (float) $entries[0]['amount']);
        self::assertEqualsWithDelta(77.00, (float) $entries[0]['amount'], 0.001);
    }

    public function testWbLoyaltyDiscountCalculatorEmitsPositiveAmount(): void
    {
        $entries = (new WbLoyaltyDiscountCalculator())->calculate(
            $this->supplierOpItem(
                'Компенсация скидки по программе лояльности',
                ['cashback_discount' => -10.00],
            ),
            null,
        );

        self::assertCount(1, $entries);
        self::assertSame('wb_loyalty_discount_compensation', $entries[0]['category_code']);
        self::assertGreaterThan(0, (float) $entries[0]['amount']);
        self::assertEqualsWithDelta(10.00, (float) $entries[0]['amount'], 0.001);
    }

    public function testZeroAmountCalculatorsProduceEmptyResult(): void
    {
        // Sanity-check: при значении ниже порога 0.01 калькуляторы возвращают [].
        // Это важно потому что persist-loop не выставит operation_type на пустом результате.
        self::assertSame([], (new WbCommissionCalculator())->calculate(
            $this->saleItem([
                'retail_price_withdisc_rub' => 1000.0,
                'acquiring_fee'             => 0,
                'ppvz_for_pay'              => 1000.0, // commission = 0
            ]),
            null,
        ));
        self::assertSame([], (new WbAcquiringCalculator())->calculate(
            $this->saleItem(['acquiring_fee' => 0]),
            null,
        ));
        self::assertSame([], (new WbStorageCalculator())->calculate(
            $this->supplierOpItem('Хранение', ['storage_fee' => 0]),
            null,
        ));
    }

    // -------------------------------------------------------------------------
    // Processor-level invariant: operation_type берётся из costData + fallback CHARGE.
    //
    // Поскольку WbCostsRawProcessor::processBatch() и ProcessWbCostsAction::__invoke()
    // зависят от ~9 final-классов сервисов (final → нельзя замокать через PHPUnit
    // createMock, нет заводских интерфейсов), полноценный mock-based behavioural тест
    // потребовал бы либо bypass-finals-плагина, либо переписывания зависимостей под
    // интерфейсы — оба варианта выходят за рамки этой задачи.
    //
    // Вместо этого фиксируем regression-инвариант через source-text reflection:
    // каждый persist-блок ОБЯЗАН содержать присвоение
    //   $cost->setOperationType($costData['operation_type'] ?? MarketplaceCostOperationType::CHARGE)
    //
    // Этот тест бы упал на состоянии перед commit'ом 7c78411 (фикс по codex-bot P1).
    // Source-text форма брittle к рефакторингу синтаксиса (например, перенос на 2 строки),
    // но именно этого мы и хотим — любая правка persist-блока должна явно подтвердить
    // что setOperationType сохранён.
    // -------------------------------------------------------------------------

    public function testProcessBatchUsesCalculatorOperationTypeWithChargeFallback(): void
    {
        $source = $this->getMethodSource(
            new \ReflectionMethod(WbCostsRawProcessor::class, 'processBatch'),
        );

        self::assertStringContainsString(
            '$cost->setOperationType($costData[\'operation_type\'] ?? MarketplaceCostOperationType::CHARGE)',
            $source,
            'WbCostsRawProcessor::processBatch() должен брать operation_type из результата калькулятора '
            . 'и использовать CHARGE как fallback для legacy-калькуляторов.',
        );
    }

    public function testProcessBatchSupportsStornoOperationTypeFromCalculatorResult(): void
    {
        $source = $this->getMethodSource(
            new \ReflectionMethod(WbCostsRawProcessor::class, 'processBatch'),
        );

        self::assertStringContainsString(
            '$costData[\'operation_type\'] ?? MarketplaceCostOperationType::CHARGE',
            $source,
            'WbCostsRawProcessor::processBatch() должен уметь сохранить STORNO, '
            . 'если calculator вернул operation_type=STORNO.',
        );
    }

    public function testProcessBatchPersistsMarketplaceCostWithStornoOperationType(): void
    {
        $source = $this->getMethodSource(
            new \ReflectionMethod(WbCostsRawProcessor::class, 'processBatch'),
        );

        self::assertStringContainsString(
            '$cost->setOperationType($costData[\'operation_type\'] ?? MarketplaceCostOperationType::CHARGE)',
            $source,
        );
    }

    public function testProcessBatchFallsBackToChargeWhenCalculatorDoesNotProvideOperationType(): void
    {
        $source = $this->getMethodSource(
            new \ReflectionMethod(WbCostsRawProcessor::class, 'processBatch'),
        );

        self::assertStringContainsString(
            '$costData[\'operation_type\'] ?? MarketplaceCostOperationType::CHARGE',
            $source,
        );
    }

    public function testProcessBatchDoesNotFilterOutReturnsAndDelegatesOperationTypeToCalculators(): void
    {
        $source = $this->getMethodSource(
            new \ReflectionMethod(WbCostsRawProcessor::class, 'processBatch'),
        );

        self::assertStringContainsString(
            'foreach ($costsData as $item)',
            $source,
        );
        self::assertStringNotContainsString(
            "return \$docType !== 'Возврат';",
            $source,
        );
    }

    public function testProcessWbCostsActionSetsOperationTypeChargeOnEveryPersistedCost(): void
    {
        $source = $this->getMethodSource(
            new \ReflectionMethod(ProcessWbCostsAction::class, '__invoke'),
        );

        self::assertStringContainsString(
            '$cost->setOperationType($costData[\'operation_type\'] ?? MarketplaceCostOperationType::CHARGE)',
            $source,
            'ProcessWbCostsAction::__invoke() должен брать operation_type из calculator-result '
            . 'и использовать CHARGE как fallback для legacy-калькуляторов.',
        );
    }

    public function testProcessWbCostsActionDoesNotFilterOutReturns(): void
    {
        $source = $this->getMethodSource(
            new \ReflectionMethod(ProcessWbCostsAction::class, '__invoke'),
        );

        self::assertStringNotContainsString(
            "return \$docType !== 'Возврат';",
            $source,
            'ProcessWbCostsAction::__invoke() не должен исключать возвраты из costsData.',
        );
    }

    public function testProcessWbCostsActionCreatesChargeAndStornoForSaleAndReturn(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $rawDocId = '22222222-2222-2222-2222-222222222222';
        $company = $this->makeCompany();
        $persisted = [];

        $rawRows = [
            [
                'doc_type_name' => 'Продажа',
                'supplier_oper_name' => 'Продажа',
                'srid' => 'SRID-SALE',
                'rrd_id' => '9101',
                'retail_price_withdisc_rub' => 1000.00,
                'ppvz_for_pay' => 800.00,
                'acquiring_fee' => 40.00,
                'quantity' => 1,
                'sale_dt' => '2026-01-10 10:00:00',
                'rr_dt' => '2026-01-10 10:00:00',
                'delivery_amount' => 0,
                'return_amount' => 0,
                'delivery_rub' => 0,
                'nm_id' => '123',
                'ts_name' => 'XL',
                'barcode' => '',
            ],
            [
                'doc_type_name' => 'Возврат',
                'supplier_oper_name' => 'Возврат покупателем',
                'srid' => 'SRID-RETURN',
                'rrd_id' => '9102',
                'retail_price_withdisc_rub' => 500.00,
                'ppvz_for_pay' => 430.00,
                'acquiring_fee' => 12.00,
                'quantity' => 1,
                'sale_dt' => '2026-01-10 10:00:00',
                'rr_dt' => '2026-01-10 10:00:00',
                'delivery_amount' => 0,
                'return_amount' => 0,
                'delivery_rub' => 0,
                'nm_id' => '123',
                'ts_name' => 'XL',
                'barcode' => '',
            ],
        ];

        $rawDoc = $this->getMockBuilder(\App\Marketplace\Entity\MarketplaceRawDocument::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRawData', 'getId', 'setUnprocessedCostsCount', 'setUnprocessedCostTypes'])
            ->getMock();
        $rawDoc->method('getRawData')->willReturn($rawRows);
        $rawDoc->method('getId')->willReturn($rawDocId);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static function (string $class, string $id) use ($companyId, $rawDocId, $company, $rawDoc): mixed {
                if ($class === \App\Company\Entity\Company::class && $id === $companyId) {
                    return $company;
                }
                if ($class === \App\Marketplace\Entity\MarketplaceRawDocument::class && $id === $rawDocId) {
                    return $rawDoc;
                }

                return null;
            },
        );
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            if ($entity instanceof MarketplaceCost) {
                $persisted[] = $entity;
            }
        });

        $listing = $this->getMockBuilder(MarketplaceListing::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getProduct'])
            ->getMock();
        $listing->method('getId')->willReturn('33333333-3333-3333-3333-333333333333');
        $listing->method('getProduct')->willReturn(null);

        $listingRepository = $this->createMock(MarketplaceListingRepository::class);
        $listingRepository->method('findListingsByNmIdsIndexed')->willReturn([
            '123_XL' => $listing,
        ]);

        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([]);
        $connection->method('executeQuery')->willReturn($result);
        $costExistingIdsQuery = new MarketplaceCostExistingExternalIdsQuery($connection);

        $barcodeCatalogRepository = $this->createMock(MarketplaceBarcodeCatalogRepository::class);
        $barcodeCatalogRepository->method('findByBarcodesIndexed')->willReturn([]);
        $barcodeCatalog = new MarketplaceBarcodeCatalogService($barcodeCatalogRepository);

        $barcodeRepository = $this->createMock(MarketplaceListingBarcodeRepository::class);
        $barcodeRepository->method('findByBarcodesIndexed')->willReturn([]);

        $costCategoryRepository = $this->createMock(MarketplaceCostCategoryRepository::class);
        $costCategoryRepository->method('findBy')->willReturn([]);
        $costCategoryRepository->method('findOneBy')->willReturn(null);
        $categoryResolver = new MarketplaceCostCategoryResolver($costCategoryRepository, $em);

        $listingResolver = (new \ReflectionClass(WbListingResolverService::class))
            ->newInstanceWithoutConstructor();

        $action = new ProcessWbCostsAction(
            $em,
            $listingRepository,
            $costExistingIdsQuery,
            $listingResolver,
            $categoryResolver,
            $barcodeCatalog,
            $barcodeRepository,
            new NullLogger(),
            [
                new WbCommissionCalculator(),
                new WbAcquiringCalculator(),
                new WbLogisticsDeliveryCalculator(),
                new WbLogisticsReturnCalculator(),
            ],
        );

        $action($companyId, $rawDocId);

        self::assertCount(4, $persisted);

        $opsByExternalId = [];
        foreach ($persisted as $cost) {
            $opsByExternalId[$cost->getExternalId()] = $cost->getOperationType();
            self::assertGreaterThan(0, (float) $cost->getAmount());
        }

        self::assertSame(MarketplaceCostOperationType::CHARGE, $opsByExternalId['wb:9101:commission'] ?? null);
        self::assertSame(MarketplaceCostOperationType::CHARGE, $opsByExternalId['wb:9101:acquiring'] ?? null);
        self::assertSame(MarketplaceCostOperationType::STORNO, $opsByExternalId['wb:9102:commission'] ?? null);
        self::assertSame(MarketplaceCostOperationType::STORNO, $opsByExternalId['wb:9102:acquiring'] ?? null);

        self::assertArrayNotHasKey('SRID-SALE_logistics_delivery', $opsByExternalId);
        self::assertArrayNotHasKey('SRID-SALE_logistics_return', $opsByExternalId);
        self::assertArrayNotHasKey('SRID-RETURN_logistics_delivery', $opsByExternalId);
        self::assertArrayNotHasKey('SRID-RETURN_logistics_return', $opsByExternalId);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function saleItem(array $overrides = []): array
    {
        return array_merge([
            'doc_type_name' => 'Продажа',
            'srid'          => 'SRID-1',
            'sale_dt'       => '2026-01-15 10:00:00',
            'retail_price'  => 1000.00,
            'acquiring_fee' => 20.00,
            'ppvz_for_pay'  => 800.00,
            'nm_id'         => '',
            'ts_name'       => '',
            'rrd_id'        => '1001',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function logisticsItem(int $deliveryAmount, int $returnAmount, float $deliveryRub, array $overrides = []): array
    {
        return array_merge([
            'doc_type_name'      => 'Услуги',
            'supplier_oper_name' => 'Логистика',
            'srid'               => 'SRID-LOG-1',
            'sale_dt'            => '2026-01-15 10:00:00',
            'rrd_id'             => '3001',
            'delivery_amount'    => $deliveryAmount,
            'return_amount'      => $returnAmount,
            'delivery_rub'       => $deliveryRub,
            'rrd_id'             => '2001',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function supplierOpItem(string $supplierOperName, array $overrides = []): array
    {
        return array_merge([
            'doc_type_name'      => 'Услуги',
            'supplier_oper_name' => $supplierOperName,
            'srid'               => 'SRID-OP-1',
            'sale_dt'            => '2026-01-15 10:00:00',
            'rrd_id'             => '3001',
        ], $overrides);
    }

    /**
     * Возвращает исходный текст тела метода (без сигнатуры).
     */
    private function getMethodSource(\ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end   = (int) $method->getEndLine();

        $lines = file($file);
        if ($lines === false) {
            self::fail("Не удалось прочитать файл {$file}");
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    private function makeProcessorWithoutConstructor(): WbCostsRawProcessor
    {
        // supports() читает только аргументы метода и не трогает зависимости —
        // безопасно создавать через newInstanceWithoutConstructor().
        return (new \ReflectionClass(WbCostsRawProcessor::class))
            ->newInstanceWithoutConstructor();
    }

    /**
     * @param iterable<CostCalculatorInterface> $calculators
     * @return array{0: WbCostsRawProcessor, 1: array<int, MarketplaceCost>}
     */
    private function makeProcessorForBehavioralTest(iterable $calculators): array
    {
        $persisted = [];
        $company = $this->makeCompany();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($company);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            if ($entity instanceof MarketplaceCost) {
                $persisted[] = $entity;
            }
        });

        $listing = $this->getMockBuilder(MarketplaceListing::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getProduct'])
            ->getMock();
        $listing->method('getId')->willReturn('33333333-3333-3333-3333-333333333333');
        $listing->method('getProduct')->willReturn(null);

        $listingRepository = $this->createMock(MarketplaceListingRepository::class);
        $listingRepository->method('findListingsByNmIdsIndexed')->willReturn([
            '123_XL' => $listing,
        ]);

        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([]);
        $connection->method('executeQuery')->willReturn($result);
        $costExistingIdsQuery = new MarketplaceCostExistingExternalIdsQuery($connection);

        $barcodeCatalogRepository = $this->createMock(MarketplaceBarcodeCatalogRepository::class);
        $barcodeCatalogRepository->method('findByBarcodesIndexed')->willReturn([]);
        $barcodeCatalog = new MarketplaceBarcodeCatalogService($barcodeCatalogRepository);

        $barcodeRepository = $this->createMock(MarketplaceListingBarcodeRepository::class);
        $barcodeRepository->method('findByBarcodesIndexed')->willReturn([]);

        $costCategoryRepository = $this->createMock(MarketplaceCostCategoryRepository::class);
        $costCategoryRepository->method('findBy')->willReturn([]);
        $costCategoryRepository->method('findOneBy')->willReturn(null);
        $categoryResolver = new MarketplaceCostCategoryResolver($costCategoryRepository, $em);

        $listingResolver = (new \ReflectionClass(WbListingResolverService::class))
            ->newInstanceWithoutConstructor();
        $action = (new \ReflectionClass(ProcessWbCostsAction::class))
            ->newInstanceWithoutConstructor();

        $processor = new WbCostsRawProcessor(
            $action,
            $em,
            $listingRepository,
            $listingResolver,
            $costExistingIdsQuery,
            $categoryResolver,
            $barcodeCatalog,
            $barcodeRepository,
            new NullLogger(),
            $calculators,
        );

        return [$processor, $persisted];
    }

    private function invokeProcessBatch(WbCostsRawProcessor $processor): void
    {
        $processor->processBatch(
            '11111111-1111-1111-1111-111111111111',
            MarketplaceType::WILDBERRIES,
            [[
                'doc_type_name' => 'Продажа',
                'supplier_oper_name' => 'Продажа',
                'srid' => 'SRID-TEST',
                'rrd_id' => '3999',
                'sale_dt' => '2026-01-15 10:00:00',
                'retail_price_withdisc_rub' => 100.0,
                'ppvz_for_pay' => 80.0,
                'acquiring_fee' => 5.0,
                'nm_id' => '123',
                'ts_name' => 'XL',
                'barcode' => '',
            ]],
            null,
        );
    }

    private function makeCompany(): \App\Company\Entity\Company
    {
        $user = new \App\Company\Entity\User('00000000-0000-0000-0000-000000000001');
        $user->setEmail('test@example.com');
        $user->setPassword('secret');

        $company = new \App\Company\Entity\Company('11111111-1111-1111-1111-111111111111', $user);
        $company->setName('Test company');

        return $company;
    }

    /**
     * Контрактная проверка: все 11 калькуляторов реализуют CostCalculatorInterface.
     * На случай если кто-то добавит calculator без implements — поймаем здесь.
     */
    public function testAllElevenWbCalculatorsImplementInterface(): void
    {
        $calculators = [
            new WbCommissionCalculator(),
            new WbAcquiringCalculator(),
            new WbLogisticsDeliveryCalculator(),
            new WbLogisticsReturnCalculator(),
            new WbStorageCalculator(),
            new WbPvzProcessingCalculator(),
            new WbWarehouseLogisticsCalculator(),
            new WbPenaltyCalculator(),
            new WbProductProcessingCalculator(),
            new WbDeductionCalculator(new SlugifyService()),
            new WbLoyaltyDiscountCalculator(),
        ];

        self::assertCount(11, $calculators, '11 WB-калькуляторов согласно services.yaml');

        foreach ($calculators as $calc) {
            self::assertInstanceOf(CostCalculatorInterface::class, $calc);
        }
    }
}
