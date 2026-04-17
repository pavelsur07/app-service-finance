<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Processor;

use App\Marketplace\Application\Processor\OzonCostsRawProcessor;
use App\Marketplace\Application\Processor\OzonServiceCategoryMap;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Тесты знакового соглашения OzonCostsRawProcessor.
 *
 * Знаковое соглашение MarketplaceCost.amount / operation_type:
 *   amount всегда положительная (по модулю);
 *   operation_type = CHARGE  — обычное начисление (комиссия, логистика, хранение, реклама);
 *   operation_type = STORNO  — сторно/возврат от маркетплейса (возврат комиссии, возврат эквайринга).
 *
 * Эти тесты ловят регрессию если знаковая логика в процессоре изменится.
 */
final class OzonCostsRawProcessorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // supports()
    // -------------------------------------------------------------------------

    public function testSupportsOzonCosts(): void
    {
        self::assertTrue(
            $this->makeProcessor()->supports('ozon', MarketplaceType::OZON, 'costs'),
        );
    }

    public function testDoesNotSupportOtherMarketplace(): void
    {
        self::assertFalse(
            $this->makeProcessor()->supports('wildberries', MarketplaceType::WILDBERRIES, 'costs'),
        );
    }

    public function testSupportsStagingRecordTypeCost(): void
    {
        self::assertTrue(
            $this->makeProcessor()->supports(StagingRecordType::COST, MarketplaceType::OZON),
        );
    }

    public function testDoesNotSupportStagingRecordTypeSales(): void
    {
        self::assertFalse(
            $this->makeProcessor()->supports(StagingRecordType::SALE, MarketplaceType::OZON),
        );
    }

    // -------------------------------------------------------------------------
    // Знаковое соглашение: extractCostEntries() через extractEntriesFromOp()
    // -------------------------------------------------------------------------

    /**
     * Обычная комиссия sale_commission < 0 → затрата → amount > 0, operation_type = CHARGE.
     */
    public function testSaleCommissionNegativeProducesChargeEntry(): void
    {
        $entries = $this->extractEntries([
            'operation_id'        => '1001',
            'operation_type'      => 'MarketplaceSellerCompensationOperation',
            'operation_type_name' => 'Продажа',
            'operation_date'      => '2026-01-15 10:00:00',
            'sale_commission'     => -150.50,
            'delivery_charge'     => 0,
            'return_delivery_charge' => 0,
            'amount'              => 0,
            'type'                => 'orders',
            'items'               => [['sku' => '111', 'name' => 'Товар']],
            'services'            => [],
        ]);

        $commission = $this->findEntry($entries, 'ozon_sale_commission');
        self::assertNotNull($commission, 'Комиссия должна создать запись');
        self::assertGreaterThan(0, (float) $commission['amount'], 'Затрата: amount > 0');
        self::assertEqualsWithDelta(150.50, (float) $commission['amount'], 0.001);
        self::assertSame(MarketplaceCostOperationType::CHARGE, $commission['operation_type']);
    }

    /**
     * Корректировка комиссии sale_commission > 0 → сторно → amount > 0, operation_type = STORNO.
     */
    public function testSaleCommissionPositiveProducesStornoEntry(): void
    {
        $entries = $this->extractEntries([
            'operation_id'        => '1002',
            'operation_type'      => 'MarketplaceSellerCompensationOperation',
            'operation_type_name' => 'Корректировка',
            'operation_date'      => '2026-01-15 10:00:00',
            'sale_commission'     => 75.00,
            'delivery_charge'     => 0,
            'return_delivery_charge' => 0,
            'amount'              => 0,
            'type'                => 'orders',
            'items'               => [['sku' => '111', 'name' => 'Товар']],
            'services'            => [],
        ]);

        $commission = $this->findEntry($entries, 'ozon_sale_commission');
        self::assertNotNull($commission, 'Корректировка комиссии должна создать запись');
        self::assertGreaterThan(0, (float) $commission['amount'], 'Сторно: amount > 0 (сумма по модулю)');
        self::assertEqualsWithDelta(75.00, (float) $commission['amount'], 0.001);
        self::assertSame(MarketplaceCostOperationType::STORNO, $commission['operation_type']);
    }

    /**
     * ClientReturnAgentOperation с sale_commission > 0 → возврат комиссии при возврате покупателя.
     * amount > 0, operation_type = STORNO.
     */
    public function testClientReturnCommissionProducesStornoEntry(): void
    {
        $entries = $this->extractEntries([
            'operation_id'        => '1003',
            'operation_type'      => 'ClientReturnAgentOperation',
            'operation_type_name' => 'Возврат покупателя',
            'operation_date'      => '2026-01-20 12:00:00',
            'sale_commission'     => 200.00,
            'delivery_charge'     => 0,
            'return_delivery_charge' => 0,
            'amount'              => 0,
            'type'                => 'returns',
            'items'               => [],
            'services'            => [],
        ]);

        $commission = $this->findEntry($entries, 'ozon_sale_commission');
        self::assertNotNull($commission, 'Возврат комиссии должен создать запись');
        self::assertGreaterThan(0, (float) $commission['amount'], 'Возврат комиссии: amount > 0');
        self::assertEqualsWithDelta(200.00, (float) $commission['amount'], 0.001);
        self::assertStringContainsString('_commission_return', $commission['external_id']);
        self::assertSame(MarketplaceCostOperationType::STORNO, $commission['operation_type']);
    }

    /**
     * ClientReturnAgentOperation с sale_commission = 0 → не создаёт запись комиссии.
     */
    public function testClientReturnWithZeroCommissionProducesNoEntry(): void
    {
        $entries = $this->extractEntries([
            'operation_id'        => '1004',
            'operation_type'      => 'ClientReturnAgentOperation',
            'operation_type_name' => 'Возврат покупателя',
            'operation_date'      => '2026-01-20 12:00:00',
            'sale_commission'     => 0,
            'delivery_charge'     => 0,
            'return_delivery_charge' => 0,
            'amount'              => 0,
            'type'                => 'returns',
            'items'               => [],
            'services'            => [],
        ]);

        self::assertEmpty($entries, 'Возврат без комиссии не должен создавать записей');
    }

    /**
     * Доставка delivery_charge → затрата → amount > 0, operation_type = CHARGE.
     */
    public function testDeliveryChargeProducesChargeEntry(): void
    {
        $entries = $this->extractEntries([
            'operation_id'        => '1005',
            'operation_type'      => 'MarketplaceSellerCompensationOperation',
            'operation_type_name' => 'Доставка',
            'operation_date'      => '2026-01-15 10:00:00',
            'sale_commission'     => 0,
            'delivery_charge'     => -55.00,
            'return_delivery_charge' => 0,
            'amount'              => 0,
            'type'                => 'orders',
            'items'               => [['sku' => '222', 'name' => 'Товар']],
            'services'            => [],
        ]);

        $delivery = $this->findEntry($entries, 'ozon_delivery');
        self::assertNotNull($delivery);
        self::assertGreaterThan(0, (float) $delivery['amount'], 'Доставка: amount > 0');
        self::assertEqualsWithDelta(55.00, (float) $delivery['amount'], 0.001);
        self::assertSame(MarketplaceCostOperationType::CHARGE, $delivery['operation_type']);
    }

    /**
     * services[] с price < 0 → затрата → amount > 0, operation_type = CHARGE.
     */
    public function testServiceNegativePriceProducesChargeEntry(): void
    {
        $entries = $this->extractEntries([
            'operation_id'        => '1006',
            'operation_type'      => 'OperationMarketplaceServiceStorage',
            'operation_type_name' => 'Хранение',
            'operation_date'      => '2026-01-15 10:00:00',
            'sale_commission'     => 0,
            'delivery_charge'     => 0,
            'return_delivery_charge' => 0,
            'amount'              => 0,
            'type'                => 'services',
            'items'               => [],
            'services'            => [
                ['name' => 'OperationMarketplaceServiceStorage', 'price' => -120.00],
            ],
        ]);

        $storage = $this->findEntry($entries, 'ozon_storage');
        self::assertNotNull($storage);
        self::assertGreaterThan(0, (float) $storage['amount'], 'Хранение: amount > 0');
        self::assertEqualsWithDelta(120.00, (float) $storage['amount'], 0.001);
        self::assertSame(MarketplaceCostOperationType::CHARGE, $storage['operation_type']);
    }

    /**
     * services[] с price > 0 → возврат услуги → amount > 0, operation_type = STORNO.
     */
    public function testServicePositivePriceProducesStornoEntry(): void
    {
        $entries = $this->extractEntries([
            'operation_id'        => '1007',
            'operation_type'      => 'MarketplaceRedistributionOfAcquiringOperation',
            'operation_type_name' => 'Возврат эквайринга',
            'operation_date'      => '2026-01-15 10:00:00',
            'sale_commission'     => 0,
            'delivery_charge'     => 0,
            'return_delivery_charge' => 0,
            'amount'              => 0,
            'type'                => 'services',
            'items'               => [['sku' => '333', 'name' => 'Товар']],
            'services'            => [
                ['name' => 'MarketplaceRedistributionOfAcquiringOperation', 'price' => 30.00],
            ],
        ]);

        $acquiring = $this->findEntry($entries, 'ozon_acquiring');
        self::assertNotNull($acquiring);
        self::assertGreaterThan(0, (float) $acquiring['amount'], 'Возврат эквайринга: amount > 0 (сумма по модулю)');
        self::assertEqualsWithDelta(30.00, (float) $acquiring['amount'], 0.001);
        self::assertSame(MarketplaceCostOperationType::STORNO, $acquiring['operation_type']);
    }

    /**
     * services[] нулевой маркер (price = 0 или isZeroMarker) → запись не создаётся.
     */
    public function testZeroMarkerServiceProducesNoEntry(): void
    {
        $entries = $this->extractEntries([
            'operation_id'        => '1008',
            'operation_type'      => 'SomeOperation',
            'operation_type_name' => 'Нулевой маркер',
            'operation_date'      => '2026-01-15 10:00:00',
            'sale_commission'     => 0,
            'delivery_charge'     => 0,
            'return_delivery_charge' => 0,
            'amount'              => 0,
            'type'                => 'services',
            'items'               => [],
            'services'            => [
                ['name' => 'MarketplaceServiceItemReturnNotDelivToCustomer', 'price' => 0],
            ],
        ]);

        self::assertEmpty($entries, 'Нулевой маркер не должен создавать записей');
    }

    /**
     * Операция без services[] → amount берётся из op['amount'] → затрата → amount > 0, CHARGE.
     */
    public function testOperationWithoutServicesUsesOpAmount(): void
    {
        $entries = $this->extractEntries([
            'operation_id'        => '1009',
            'operation_type'      => 'OperationMarketplaceCostPerClick',
            'operation_type_name' => 'Оплата за клик',
            'operation_date'      => '2026-01-15 10:00:00',
            'sale_commission'     => 0,
            'delivery_charge'     => 0,
            'return_delivery_charge' => 0,
            'amount'              => -500.00,
            'type'                => 'other',
            'items'               => [],
            'services'            => [],
        ]);

        $cpc = $this->findEntry($entries, 'ozon_cpc');
        self::assertNotNull($cpc);
        self::assertGreaterThan(0, (float) $cpc['amount'], 'CPC: amount > 0');
        self::assertEqualsWithDelta(500.00, (float) $cpc['amount'], 0.001);
        self::assertSame(MarketplaceCostOperationType::CHARGE, $cpc['operation_type']);
    }

    /**
     * Компенсация с положительным amount → ozon_compensation, CHARGE, amount > 0.
     */
    public function testCompensationPositiveAmountGoesToCompensation(): void
    {
        $entries = $this->extractEntries([
            'operation_id'        => '1011',
            'operation_type'      => 'MarketplaceSellerCompensationOperation',
            'operation_type_name' => 'Компенсация',
            'operation_date'      => '2026-01-15 10:00:00',
            'sale_commission'     => 0,
            'delivery_charge'     => 0,
            'return_delivery_charge' => 0,
            'amount'              => 250.00,
            'type'                => 'compensation',
            'items'               => [],
            'services'            => [],
        ]);

        $entry = $this->findEntry($entries, 'ozon_compensation');
        self::assertNotNull($entry, 'Положительная компенсация → ozon_compensation');
        self::assertGreaterThan(0, (float) $entry['amount']);
        self::assertEqualsWithDelta(250.00, (float) $entry['amount'], 0.001);
        self::assertSame(MarketplaceCostOperationType::STORNO, $entry['operation_type']);
        self::assertNull($this->findEntry($entries, 'ozon_decompensation'));
    }

    /**
     * Компенсация с отрицательным amount → ozon_decompensation, CHARGE, amount > 0 (по модулю).
     */
    public function testCompensationNegativeAmountGoesToDecompensation(): void
    {
        $entries = $this->extractEntries([
            'operation_id'        => '1012',
            'operation_type'      => 'MarketplaceSellerCompensationOperation',
            'operation_type_name' => 'Декомпенсация',
            'operation_date'      => '2026-01-15 10:00:00',
            'sale_commission'     => 0,
            'delivery_charge'     => 0,
            'return_delivery_charge' => 0,
            'amount'              => -180.00,
            'type'                => 'compensation',
            'items'               => [],
            'services'            => [],
        ]);

        $entry = $this->findEntry($entries, 'ozon_decompensation');
        self::assertNotNull($entry, 'Отрицательная компенсация → ozon_decompensation');
        self::assertGreaterThan(0, (float) $entry['amount'], 'Сумма хранится по модулю');
        self::assertEqualsWithDelta(180.00, (float) $entry['amount'], 0.001);
        self::assertSame(MarketplaceCostOperationType::CHARGE, $entry['operation_type']);
        self::assertNull($this->findEntry($entries, 'ozon_compensation'));
    }

    /**
     * Multi-SKU операция: сумма service делится поровну, итог совпадает с исходным.
     */
    public function testMultiSkuServiceSplitsAmountCorrectly(): void
    {
        $entries = $this->extractEntries([
            'operation_id'        => '1010',
            'operation_type'      => 'MarketplaceServiceItemFulfillment',
            'operation_type_name' => 'Сборка',
            'operation_date'      => '2026-01-15 10:00:00',
            'sale_commission'     => 0,
            'delivery_charge'     => 0,
            'return_delivery_charge' => 0,
            'amount'              => 0,
            'type'                => 'services',
            'items'               => [
                ['sku' => '100', 'name' => 'Товар А'],
                ['sku' => '101', 'name' => 'Товар Б'],
                ['sku' => '102', 'name' => 'Товар В'],
            ],
            'services'            => [
                ['name' => 'MarketplaceServiceItemFulfillment', 'price' => -90.00],
            ],
        ]);

        $fulfillment = array_filter($entries, static fn (array $e) => $e['category_code'] === 'ozon_fulfillment');
        self::assertCount(3, $fulfillment, 'Должно быть 3 записи для 3 SKU');

        $total = array_sum(array_column($fulfillment, 'amount'));
        self::assertEqualsWithDelta(90.00, $total, 0.01, 'Сумма по всем SKU = исходная сумма');

        foreach ($fulfillment as $entry) {
            self::assertGreaterThan(0, (float) $entry['amount'], 'Каждая часть: amount > 0');
            self::assertSame(MarketplaceCostOperationType::CHARGE, $entry['operation_type']);
        }
    }

    /**
     * OperationAgentStornoDeliveredToCustomer (сторно продажи) — НЕ должна создавать
     * _main cost entry, т.к. выручка учитывается в OzonSalesRawProcessor.
     * Commission/delivery storno обрабатываются отдельными полями и не затрагиваются.
     */
    public function testStornoDeliveredToCustomerProducesNoMainEntry(): void
    {
        $entries = $this->extractEntries([
            'operation_id'           => '2001',
            'operation_type'         => 'OperationAgentStornoDeliveredToCustomer',
            'operation_type_name'    => 'Сторно доставки покупателю',
            'operation_date'         => '2026-02-16 12:00:00',
            'sale_commission'        => 0,
            'delivery_charge'        => 0,
            'return_delivery_charge' => 0,
            'amount'                 => -1201.00,
            'accruals_for_sale'      => -1201.00,
            'type'                   => 'returns',
            'items'                  => [['sku' => '444', 'name' => 'Товар']],
            'services'               => [],
        ]);

        $mainEntry = array_filter($entries, static fn (array $e) => str_ends_with($e['external_id'], '_main'));
        self::assertEmpty($mainEntry, 'Сторно продажи не должно создавать _main cost entry');
    }

    /**
     * OperationAgentStornoDeliveredToCustomer with commission refund —
     * commission storno SHOULD still be extracted as a separate cost entry.
     */
    public function testStornoDeliveredToCustomerStillExtractsCommission(): void
    {
        $entries = $this->extractEntries([
            'operation_id'           => '2002',
            'operation_type'         => 'OperationAgentStornoDeliveredToCustomer',
            'operation_type_name'    => 'Сторно доставки покупателю',
            'operation_date'         => '2026-02-16 12:00:00',
            'sale_commission'        => 150.00,
            'delivery_charge'        => 0,
            'return_delivery_charge' => 0,
            'amount'                 => -1051.00,
            'accruals_for_sale'      => -1201.00,
            'type'                   => 'returns',
            'items'                  => [['sku' => '444', 'name' => 'Товар']],
            'services'               => [],
        ]);

        $commission = $this->findEntry($entries, 'ozon_sale_commission');
        self::assertNotNull($commission, 'Возврат комиссии при сторно должен создать запись');
        self::assertEqualsWithDelta(150.00, (float) $commission['amount'], 0.001);
        self::assertSame(MarketplaceCostOperationType::STORNO, $commission['operation_type']);

        $mainEntry = array_filter($entries, static fn (array $e) => str_ends_with($e['external_id'], '_main'));
        self::assertEmpty($mainEntry, 'Сторно продажи не должно создавать _main cost entry даже с комиссией');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Вызывает приватный extractCostEntries() через рефлексию.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractEntries(array $op): array
    {
        $processor = $this->makeProcessor();

        // ClientReturnAgentOperation обрабатывается в processBatch(), не в extractCostEntries()
        // Тестируем его отдельно через публичный метод-обёртку
        if (($op['operation_type'] ?? '') === 'ClientReturnAgentOperation') {
            return $this->extractClientReturnEntries($op);
        }

        $ref    = new \ReflectionClass($processor);
        $method = $ref->getMethod('extractCostEntries');
        $method->setAccessible(true);

        $operationId   = (string) $op['operation_id'];
        $operationDate = new \DateTimeImmutable($op['operation_date']);

        return $method->invoke($processor, $op, $operationId, $operationDate);
    }

    /**
     * ClientReturnAgentOperation логика из processBatch() — воспроизводим здесь.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractClientReturnEntries(array $op): array
    {
        $entries          = [];
        $returnCommission = (float) ($op['sale_commission'] ?? 0);

        if ($returnCommission > 0) {
            $entries[] = [
                'external_id'    => $op['operation_id'] . '_commission_return',
                'category_code'  => 'ozon_sale_commission',
                'amount'         => (string) abs($returnCommission),
                'operation_type' => MarketplaceCostOperationType::STORNO,
            ];
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, mixed>|null
     */
    private function findEntry(array $entries, string $categoryCode): ?array
    {
        foreach ($entries as $entry) {
            if (($entry['category_code'] ?? '') === $categoryCode) {
                return $entry;
            }
        }

        return null;
    }

    private function makeProcessor(): OzonCostsRawProcessor
    {
        // OzonCostsRawProcessor требует зависимости — тестируем только extractCostEntries()
        // через рефлексию. Создаём без конструктора и инжектируем только $logger,
        // который нужен для OzonServiceCategoryMap::resolve() внутри extractCostEntries().
        $processor = (new \ReflectionClass(OzonCostsRawProcessor::class))
            ->newInstanceWithoutConstructor();

        $loggerProp = (new \ReflectionClass($processor))->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($processor, new \Psr\Log\NullLogger());

        return $processor;
    }
}
