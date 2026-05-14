<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Wildberries;

use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use PHPUnit\Framework\TestCase;

final class WbSalesReportRowNormalizerTest extends TestCase
{
    private WbSalesReportRowNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new WbSalesReportRowNormalizer();
    }

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
            'quantity' => 2,
            'srid' => 'srid-1',
            'rr_dt' => '2026-05-01 12:00:00',
            'sale_dt' => '2026-05-01 11:00:00',
        ];
    }

    private function oldReturnRow(): array
    {
        $row = $this->oldSaleRow();
        $row['doc_type_name'] = 'Возврат';
        $row['supplier_oper_name'] = 'Возврат покупателем';

        return $row;
    }

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
            'quantity' => 2,
            'srid' => 'srid-1',
            'rrDate' => '2026-05-01 12:00:00',
            'saleDt' => '2026-05-01 11:00:00',
        ];
    }

    private function newReturnRow(): array
    {
        $row = $this->newSaleRow();
        $row['docTypeName'] = 'Return';
        $row['sellerOperName'] = 'Return operation';

        return $row;
    }
}
