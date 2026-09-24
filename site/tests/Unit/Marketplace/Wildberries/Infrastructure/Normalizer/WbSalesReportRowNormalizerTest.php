<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Wildberries\Infrastructure\Normalizer;

use App\Marketplace\Wildberries\Infrastructure\Normalizer\WbSalesReportRowNormalizer;
use PHPUnit\Framework\TestCase;

final class WbSalesReportRowNormalizerTest extends TestCase
{
    private WbSalesReportRowNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new WbSalesReportRowNormalizer();
    }

    // Поля, суммы и фолбэки: snake_case и camelCase ключи, выручка без СПП, пустые значения.

    public function testSnakeCaseAndCamelCaseRowsAreNormalizedEqually(): void
    {
        $snakeCase = [
            'supplier_oper_name' => 'Логистика продажа',
            'doc_type_name' => 'Продажа',
            'nm_id' => '12345',
            'ts_name' => 'M',
            'barcode' => '4600000000001',
            'sa_name' => 'ART-1',
            'brand_name' => 'Brand X',
            'subject_name' => 'T-Shirt',
            'retail_price' => '1500.50',
            'retail_price_withdisc_rub' => '1300.40',
            'ppvz_for_pay' => '1200.30',
            'acquiring_fee' => '20.10',
            'ppvz_vw' => '60.00',
            'ppvz_vw_nds' => '19.90',
            'delivery_amount' => '2',
            'return_amount' => '0',
            'delivery_rub' => '15.5',
            'storage_fee' => '',
            'acceptance' => null,
            'rebill_logistic_cost' => '3.2',
            'bonus_type_name' => 'Скидка WB',
            'ppvz_reward' => '1.5',
            'cashback_discount' => '0.7',
            'sale_dt' => '2026-05-01 11:00:00',
            'rr_dt' => '2026-05-01 12:00:00',
        ];

        $camelCase = [
            'sellerOperName' => 'Логистика продажа',
            'docTypeName' => 'Продажа',
            'nmId' => '12345',
            'techSize' => 'M',
            'sku' => '4600000000001',
            'vendorCode' => 'ART-1',
            'brandName' => 'Brand X',
            'subjectName' => 'T-Shirt',
            'retailPrice' => '1500.50',
            'retailPriceWithDisc' => '1300.40',
            'forPay' => '1200.30',
            'acquiringFee' => '20.10',
            'ppvzVw' => '60.00',
            'ppvzVwNds' => '19.90',
            'deliveryAmount' => '2',
            'returnAmount' => '0',
            'deliveryService' => '15.5',
            'paidStorage' => '',
            'paidAcceptance' => null,
            'rebillLogisticCost' => '3.2',
            'bonusTypeName' => 'Скидка WB',
            'ppvzReward' => '1.5',
            'cashbackDiscount' => '0.7',
            'saleDt' => '2026-05-01 11:00:00',
            'rrDate' => '2026-05-01 12:00:00',
        ];

        self::assertSame($this->normalizer->operationName($snakeCase), $this->normalizer->operationName($camelCase));
        self::assertSame($this->normalizer->docTypeName($snakeCase), $this->normalizer->docTypeName($camelCase));
        self::assertSame($this->normalizer->nmId($snakeCase), $this->normalizer->nmId($camelCase));
        self::assertSame($this->normalizer->techSize($snakeCase), $this->normalizer->techSize($camelCase));
        self::assertSame($this->normalizer->barcode($snakeCase), $this->normalizer->barcode($camelCase));
        self::assertSame($this->normalizer->vendorCode($snakeCase), $this->normalizer->vendorCode($camelCase));
        self::assertSame($this->normalizer->brandName($snakeCase), $this->normalizer->brandName($camelCase));
        self::assertSame($this->normalizer->subjectName($snakeCase), $this->normalizer->subjectName($camelCase));
        self::assertSame($this->normalizer->retailPrice($snakeCase), $this->normalizer->retailPrice($camelCase));
        self::assertSame($this->normalizer->retailPriceWithDisc($snakeCase), $this->normalizer->retailPriceWithDisc($camelCase));
        self::assertSame($this->normalizer->forPay($snakeCase), $this->normalizer->forPay($camelCase));
        self::assertSame($this->normalizer->acquiringFee($snakeCase), $this->normalizer->acquiringFee($camelCase));
        self::assertSame($this->normalizer->ppvzVw($snakeCase), $this->normalizer->ppvzVw($camelCase));
        self::assertSame($this->normalizer->ppvzVwNds($snakeCase), $this->normalizer->ppvzVwNds($camelCase));
        self::assertSame($this->normalizer->fullMarketplaceCommission($snakeCase), $this->normalizer->fullMarketplaceCommission($camelCase));
        self::assertSame($this->normalizer->deliveryAmount($snakeCase), $this->normalizer->deliveryAmount($camelCase));
        self::assertSame($this->normalizer->returnAmount($snakeCase), $this->normalizer->returnAmount($camelCase));
        self::assertSame($this->normalizer->deliveryService($snakeCase), $this->normalizer->deliveryService($camelCase));
        self::assertSame($this->normalizer->paidStorage($snakeCase), $this->normalizer->paidStorage($camelCase));
        self::assertSame($this->normalizer->paidAcceptance($snakeCase), $this->normalizer->paidAcceptance($camelCase));
        self::assertSame($this->normalizer->rebillLogisticCost($snakeCase), $this->normalizer->rebillLogisticCost($camelCase));
        self::assertSame($this->normalizer->bonusTypeName($snakeCase), $this->normalizer->bonusTypeName($camelCase));
        self::assertSame($this->normalizer->ppvzReward($snakeCase), $this->normalizer->ppvzReward($camelCase));
        self::assertSame($this->normalizer->cashbackDiscount($snakeCase), $this->normalizer->cashbackDiscount($camelCase));
        self::assertSame(
            $this->normalizer->operationDate($snakeCase)->format('Y-m-d H:i:s'),
            $this->normalizer->operationDate($camelCase)->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            $this->normalizer->reportDate($snakeCase)->format('Y-m-d H:i:s'),
            $this->normalizer->reportDate($camelCase)->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Регрессия (issue: REV_NOT_SPP == REV_SPP_SALES при закрытии месяца WB).
     *
     * Реальная строка finance API (/api/finance/v1/sales-reports/detailed):
     * комиссия приходит в ключах `vw`/`vwNds` (не `ppvzVw`), а выручка без СПП —
     * это цена продавца retailPriceWithDisc × qty, а не forPay + комиссия + эквайринг
     * (реконструкция не сходится: forPay учитывает СПП-компенсации WB).
     */
    public function testFinanceApiRowGrossWithoutSppIsSellerPriceAndVwKeysAreRead(): void
    {
        $row = [
            'docTypeName' => 'Продажа',
            'quantity' => 1,
            'retailPrice' => '660',
            'retailPriceWithDisc' => '660',
            'retailAmount' => '364',
            'forPay' => '454.04',
            'vw' => '-94.5680327868852459',
            'vwNds' => '-20.8',
            'acquiringFee' => '14.56',
            'spp' => '44.85',
        ];

        self::assertEqualsWithDelta(115.37, $this->normalizer->fullMarketplaceCommission($row), 0.01);
        self::assertSame(660.0, $this->normalizer->grossWithoutSpp($row));
        self::assertSame(364.0, $this->normalizer->retailAmount($row));
    }

    public function testStringFallbackSkipsEmptyCamelCaseAndUsesSnakeCase(): void
    {
        $row = [
            'sellerOperName' => '',
            'supplier_oper_name' => 'Продажа',
        ];

        self::assertSame('Продажа', $this->normalizer->sellerOperName($row));
    }

    public function testNullableStringFallbackSkipsEmptyCamelCaseAndUsesSnakeCase(): void
    {
        $row = [
            'techSize' => '',
            'ts_name' => 'M',
        ];

        self::assertSame('M', $this->normalizer->techSize($row));
    }

    public function testFloatFallbackSkipsEmptyCamelCaseAndUsesSnakeCase(): void
    {
        $row = [
            'retailPriceWithDisc' => '',
            'retail_price_withdisc_rub' => '1300.40',
        ];

        self::assertSame(1300.40, $this->normalizer->retailPriceWithDisc($row));
    }

    public function testFloatReturnsZeroWhenAllFallbackValuesAreEmpty(): void
    {
        $row = [
            'paidStorage' => '',
            'storage_fee' => null,
        ];

        self::assertSame(0.0, $this->normalizer->paidStorage($row));
    }

    public function testOperationDateFallbackSkipsEmptySaleDtAndUsesRrDate(): void
    {
        $row = [
            'saleDt' => '',
            'rrDate' => '2026-05-01',
        ];

        self::assertSame('2026-05-01', $this->normalizer->operationDate($row)->format('Y-m-d'));
    }

    // Классификация продажа/возврат и даты.

    public function testNormalizesOldSnakeCaseSaleRow(): void
    {
        $row = $this->oldSaleRow();

        self::assertSame('1001', $this->normalizer->rrdId($row));
        self::assertSame('srid-1', $this->normalizer->srid($row));
        self::assertSame('Продажа', $this->normalizer->docTypeName($row));
        self::assertSame('Логистика продажа', $this->normalizer->sellerOperName($row));
        self::assertSame('12345', $this->normalizer->nmId($row));
        self::assertSame('ART-1', $this->normalizer->vendorCode($row));
        self::assertSame('M', $this->normalizer->techSize($row));
        self::assertSame('4600000000001', $this->normalizer->barcode($row));
        self::assertSame(2, $this->normalizer->quantity($row));
        self::assertSame(1300.0, $this->normalizer->retailPriceWithDisc($row));
        self::assertSame(80.0, $this->normalizer->fullMarketplaceCommission($row));
        self::assertSame(2600.0, $this->normalizer->grossWithoutSpp($row));
        self::assertTrue($this->normalizer->isSale($row));
        self::assertFalse($this->normalizer->isReturn($row));
    }

    public function testNormalizesOldSnakeCaseReturnRow(): void
    {
        $row = $this->oldReturnRow();

        self::assertTrue($this->normalizer->isReturn($row));
        self::assertFalse($this->normalizer->isSale($row));
        self::assertTrue($this->normalizer->isSaleOrReturn($row));
    }

    public function testNormalizesNewCamelCaseSaleRow(): void
    {
        $row = $this->newSaleRow();

        self::assertSame('1001', $this->normalizer->rrdId($row));
        self::assertSame('srid-1', $this->normalizer->srid($row));
        self::assertSame('12345', $this->normalizer->nmId($row));
        self::assertSame('ART-1', $this->normalizer->vendorCode($row));
        self::assertSame('M', $this->normalizer->techSize($row));
        self::assertSame('4600000000001', $this->normalizer->barcode($row));
        self::assertSame(1500.0, $this->normalizer->retailPrice($row));
        self::assertSame(1500.0, $this->normalizer->retailAmount($row));
        self::assertSame(1300.0, $this->normalizer->retailPriceWithDisc($row));
        self::assertSame(1200.0, $this->normalizer->forPay($row));
        self::assertSame(20.0, $this->normalizer->acquiringFee($row));
        self::assertSame(80.0, $this->normalizer->fullMarketplaceCommission($row));
        self::assertSame(2600.0, $this->normalizer->grossWithoutSpp($row));
        self::assertTrue($this->normalizer->isSale($row));
    }

    public function testNormalizesNewCamelCaseReturnRow(): void
    {
        $row = $this->newReturnRow();

        self::assertTrue($this->normalizer->isReturn($row));
        self::assertFalse($this->normalizer->isSale($row));
        self::assertTrue($this->normalizer->isSaleOrReturn($row));
    }

    public function testPvzCompensationWithReturnWordIsStillSaleByDocTypeOnly(): void
    {
        $row = [
            'doc_type_name' => 'Продажа',
            'supplier_oper_name' => 'Возмещение за выдачу и возврат товаров на ПВЗ',
        ];

        self::assertTrue($this->normalizer->isSale($row));
        self::assertFalse($this->normalizer->isReturn($row));
        self::assertTrue($this->normalizer->isSaleOrReturn($row));
    }

    public function testRowWithoutDocTypeButWithReturnWordInOperationIsNotReturn(): void
    {
        $row = [
            'doc_type_name' => '',
            'supplier_oper_name' => 'Возмещение за выдачу и возврат товаров на ПВЗ',
        ];

        self::assertFalse($this->normalizer->isSale($row));
        self::assertFalse($this->normalizer->isReturn($row));
        self::assertFalse($this->normalizer->isSaleOrReturn($row));
    }

    public function testUnknownDocTypeNameIsNotSaleOrReturn(): void
    {
        $row = [
            'docTypeName' => 'Correction',
            'sellerOperName' => 'Return operation',
        ];

        self::assertFalse($this->normalizer->isSale($row));
        self::assertFalse($this->normalizer->isReturn($row));
        self::assertFalse($this->normalizer->isSaleOrReturn($row));
    }

    public function testReportDateThrowsWhenDateFieldsAreMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WB report row must contain rrDate or rr_dt.');

        $this->normalizer->reportDate([]);
    }

    public function testOperationDateThrowsWhenDateFieldsAreMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WB report row must contain saleDt/sale_dt or rrDate/rr_dt.');

        $this->normalizer->operationDate([]);
    }

    public function testRrdIdReturnsNullWhenMissing(): void
    {
        self::assertNull($this->normalizer->rrdId(['srid' => 'srid-2']));
    }

    /**
     * @return array<string, mixed>
     */
    private function oldSaleRow(): array
    {
        return [
            'rrd_id' => '1001',
            'doc_type_name' => 'Продажа',
            'supplier_oper_name' => 'Логистика продажа',
            'nm_id' => '12345',
            'sa_name' => 'ART-1',
            'ts_name' => 'M',
            'barcode' => '4600000000001',
            'retail_price' => '1500',
            'retail_amount' => '1500',
            'retail_price_withdisc_rub' => '1300',
            'ppvz_for_pay' => '1200',
            'acquiring_fee' => '20',
            'ppvz_vw' => '60',
            'ppvz_vw_nds' => '20',
            'quantity' => 2,
            'srid' => 'srid-1',
            'rr_dt' => '2026-05-01 12:00:00',
            'sale_dt' => '2026-05-01 11:00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function oldReturnRow(): array
    {
        $row = $this->oldSaleRow();
        $row['doc_type_name'] = 'Возврат';
        $row['supplier_oper_name'] = 'Возврат покупателем';

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function newSaleRow(): array
    {
        return [
            'rrdId' => '1001',
            'docTypeName' => 'Sale',
            'sellerOperName' => 'Sale operation',
            'nmId' => '12345',
            'vendorCode' => 'ART-1',
            'techSize' => 'M',
            'sku' => '4600000000001',
            'retailPrice' => '1500',
            'retailAmount' => '1500',
            'retailPriceWithDisc' => '1300',
            'forPay' => '1200',
            'acquiringFee' => '20',
            'ppvzVw' => '60',
            'ppvzVwNds' => '20',
            'quantity' => 2,
            'srid' => 'srid-1',
            'rrDate' => '2026-05-01 12:00:00',
            'saleDt' => '2026-05-01 11:00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newReturnRow(): array
    {
        $row = $this->newSaleRow();
        $row['docTypeName'] = 'Return';
        $row['sellerOperName'] = 'Return operation';

        return $row;
    }
}
