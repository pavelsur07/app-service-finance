<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Normalizer\Wildberries;

use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbReportRowClassifier;
use PHPUnit\Framework\TestCase;

final class WbReportRowClassifierTest extends TestCase
{
    private WbReportRowClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new WbReportRowClassifier();
    }

    public function testCamelCaseSaleClassifiedAsSale(): void
    {
        $row = ['sellerOperName' => 'Продажа', 'docTypeName' => 'Продажа'];

        self::assertSame(StagingRecordType::SALE, $this->classifier->classify($row));
    }

    public function testSnakeCaseSaleClassifiedAsSale(): void
    {
        $row = ['supplier_oper_name' => 'Продажа', 'doc_type_name' => 'Продажа'];

        self::assertSame(StagingRecordType::SALE, $this->classifier->classify($row));
    }

    public function testCamelCaseReturnClassifiedAsReturn(): void
    {
        $row = ['sellerOperName' => 'Возврат', 'docTypeName' => 'Возврат'];

        self::assertSame(StagingRecordType::RETURN, $this->classifier->classify($row));
    }

    public function testSnakeCaseReturnClassifiedAsReturn(): void
    {
        $row = ['supplier_oper_name' => 'Возврат', 'doc_type_name' => 'Возврат'];

        self::assertSame(StagingRecordType::RETURN, $this->classifier->classify($row));
    }

    public function testCamelCaseLogisticsClassifiedAsCost(): void
    {
        $row = ['sellerOperName' => 'Логистика', 'docTypeName' => ''];

        self::assertSame(StagingRecordType::COST, $this->classifier->classify($row));
    }

    public function testSnakeCaseLogisticsClassifiedAsCost(): void
    {
        $row = ['supplier_oper_name' => 'Логистика', 'doc_type_name' => ''];

        self::assertSame(StagingRecordType::COST, $this->classifier->classify($row));
    }


    public function testSnakeCaseLogisticsCorrectionClassifiedAsCost(): void
    {
        $row = ['supplier_oper_name' => 'Коррекция логистики', 'doc_type_name' => ''];

        self::assertSame(StagingRecordType::COST, $this->classifier->classify($row));
    }

    public function testCamelCaseLogisticsCorrectionClassifiedAsCost(): void
    {
        $row = ['sellerOperName' => 'Коррекция логистики', 'docTypeName' => ''];

        self::assertSame(StagingRecordType::COST, $this->classifier->classify($row));
    }

    public function testCamelCaseFallbackToDocTypeWhenSellerOperNameIsEmpty(): void
    {
        $row = ['sellerOperName' => '', 'docTypeName' => 'Продажа'];

        self::assertSame(StagingRecordType::SALE, $this->classifier->classify($row));
    }

    public function testSnakeCaseFallbackToDocTypeWhenSupplierOperNameIsEmpty(): void
    {
        $row = ['supplier_oper_name' => '', 'doc_type_name' => 'Возврат'];

        self::assertSame(StagingRecordType::RETURN, $this->classifier->classify($row));
    }

    public function testEmptyOperationValuesClassifiedAsOther(): void
    {
        $row = ['sellerOperName' => '', 'docTypeName' => ''];

        self::assertSame(StagingRecordType::OTHER, $this->classifier->classify($row));
    }

    public function testPriyomkaWithYoClassifiedAsCost(): void
    {
        $row = ['sellerOperName' => 'Приёмка', 'docTypeName' => ''];

        self::assertSame(StagingRecordType::COST, $this->classifier->classify($row));
    }

}
