<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Processor;

use App\Marketplace\Application\ProcessWbCostsAction;
use App\Marketplace\Application\Processor\WbCostsRawProcessor;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
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
use PHPUnit\Framework\TestCase;

/**
 * Тесты знакового соглашения WbCostsRawProcessor / WB-калькуляторов.
 *
 * Знаковое соглашение MarketplaceCost.amount / operation_type для WB:
 *   amount всегда положительная (по модулю);
 *   operation_type = CHARGE — все WB-затраты рассматриваются как charge'ы.
 *   (WB не эмитирует storno на уровне отчётов — возвраты обрабатываются
 *    отдельным processReturnsFromRaw, а не WbCostsRawProcessor.)
 *
 * Эти тесты ловят регрессию если знаковая логика в калькуляторах изменится
 * или если из persist-блока процессора пропадёт setOperationType(CHARGE)
 * (см. Phase 2B; codex-bot review на PR #1508 уже один раз поймал такой пропуск
 * в ProcessWbCostsAction::__invoke()).
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

    /** @dataProvider commissionScenarios */
    public function testWbCommissionCalculatorEmitsPositiveAmount(
        float $retailPrice,
        float $acquiringFee,
        float $ppvzForPay,
        float $expectedAbs,
    ): void {
        $entries = (new WbCommissionCalculator())->calculate(
            $this->saleItem([
                'retail_price'  => $retailPrice,
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
        // commission = retail - acquiring - ppvzForPay
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
                'retail_price'  => 1000.0,
                'acquiring_fee' => 0,
                'ppvz_for_pay'  => 1000.0, // commission = 0
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
    // Processor-level invariant: setOperationType(CHARGE) на каждом persisted MarketplaceCost.
    //
    // Поскольку WbCostsRawProcessor::processBatch() и ProcessWbCostsAction::__invoke()
    // зависят от ~9 final-классов сервисов (final → нельзя замокать через PHPUnit
    // createMock, нет заводских интерфейсов), полноценный mock-based behavioural тест
    // потребовал бы либо bypass-finals-плагина, либо переписывания зависимостей под
    // интерфейсы — оба варианта выходят за рамки этой задачи.
    //
    // Вместо этого фиксируем regression-инвариант через source-text reflection:
    // каждый persist-блок ОБЯЗАН содержать литерал
    //   $cost->setOperationType(MarketplaceCostOperationType::CHARGE)
    //
    // Этот тест бы упал на состоянии перед commit'ом 7c78411 (фикс по codex-bot P1).
    // Source-text форма брittle к рефакторингу синтаксиса (например, перенос на 2 строки),
    // но именно этого мы и хотим — любая правка persist-блока должна явно подтвердить
    // что setOperationType сохранён.
    // -------------------------------------------------------------------------

    public function testProcessBatchSetsOperationTypeChargeOnEveryPersistedCost(): void
    {
        $source = $this->getMethodSource(
            new \ReflectionMethod(WbCostsRawProcessor::class, 'processBatch'),
        );

        self::assertStringContainsString(
            '$cost->setOperationType(MarketplaceCostOperationType::CHARGE)',
            $source,
            'WbCostsRawProcessor::processBatch() МУСТ выставлять operation_type=CHARGE на каждом '
            . 'persisted MarketplaceCost. Без этого post-Phase-2B SQL-запросы группирующие по '
            . '(c.operation_type = \'storno\') трактуют NULL отдельно от FALSE → дубликаты PL-линий '
            . 'в close/preview flow (см. UnprocessedCostsQuery::execute, codex-bot review #1508).',
        );
    }

    public function testProcessBatchFiltersOutReturns(): void
    {
        $source = $this->getMethodSource(
            new \ReflectionMethod(WbCostsRawProcessor::class, 'processBatch'),
        );

        self::assertMatchesRegularExpression(
            "/array_filter\\(\\s*\\\$rawRows[\\s\\S]+?'doc_type_name'[\\s\\S]+?'Возврат'/u",
            $source,
            'WbCostsRawProcessor::processBatch() ОБЯЗАН отфильтровывать строки '
            . 'doc_type_name=Возврат — они обрабатываются отдельным processReturnsFromRaw, '
            . 'не должны порождать MarketplaceCost.',
        );
    }

    public function testProcessWbCostsActionSetsOperationTypeChargeOnEveryPersistedCost(): void
    {
        // Регрессионный тест на codex-bot P1 (fix 7c78411). До фикса этот тест бы упал.
        $source = $this->getMethodSource(
            new \ReflectionMethod(ProcessWbCostsAction::class, '__invoke'),
        );

        self::assertStringContainsString(
            '$cost->setOperationType(MarketplaceCostOperationType::CHARGE)',
            $source,
            'ProcessWbCostsAction::__invoke() МУСТ выставлять operation_type=CHARGE на каждом '
            . 'persisted MarketplaceCost. WbCostsRawProcessor::process() делегирует сюда '
            . '(см. WbCostsRawProcessor.php:54-57), поэтому пропуск здесь означает что новые WB-строки '
            . 'через raw-doc pipeline уйдут с operation_type=NULL — тот же класс багов что в '
            . 'processBatch (codex-bot review #1508).',
        );
    }

    public function testProcessWbCostsActionFiltersOutReturns(): void
    {
        $source = $this->getMethodSource(
            new \ReflectionMethod(ProcessWbCostsAction::class, '__invoke'),
        );

        self::assertMatchesRegularExpression(
            "/array_filter\\([\\s\\S]+?'doc_type_name'[\\s\\S]+?'Возврат'/u",
            $source,
            'ProcessWbCostsAction::__invoke() ОБЯЗАН отфильтровывать строки '
            . 'doc_type_name=Возврат на входе.',
        );
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
            'delivery_amount'    => $deliveryAmount,
            'return_amount'      => $returnAmount,
            'delivery_rub'       => $deliveryRub,
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
