<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Normalizer\Wildberries;

use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use PHPUnit\Framework\TestCase;

final class WbSalesReportRowNormalizerTest extends TestCase
{
    public function testSnakeCaseAndCamelCaseRowsAreNormalizedEqually(): void
    {
        $normalizer = new WbSalesReportRowNormalizer();

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

        self::assertSame($normalizer->operationName($snakeCase), $normalizer->operationName($camelCase));
        self::assertSame($normalizer->docTypeName($snakeCase), $normalizer->docTypeName($camelCase));
        self::assertSame($normalizer->nmId($snakeCase), $normalizer->nmId($camelCase));
        self::assertSame($normalizer->techSize($snakeCase), $normalizer->techSize($camelCase));
        self::assertSame($normalizer->barcode($snakeCase), $normalizer->barcode($camelCase));
        self::assertSame($normalizer->vendorCode($snakeCase), $normalizer->vendorCode($camelCase));
        self::assertSame($normalizer->brandName($snakeCase), $normalizer->brandName($camelCase));
        self::assertSame($normalizer->subjectName($snakeCase), $normalizer->subjectName($camelCase));
        self::assertSame($normalizer->retailPrice($snakeCase), $normalizer->retailPrice($camelCase));
        self::assertSame($normalizer->retailPriceWithDisc($snakeCase), $normalizer->retailPriceWithDisc($camelCase));
        self::assertSame($normalizer->forPay($snakeCase), $normalizer->forPay($camelCase));
        self::assertSame($normalizer->acquiringFee($snakeCase), $normalizer->acquiringFee($camelCase));
        self::assertSame($normalizer->deliveryAmount($snakeCase), $normalizer->deliveryAmount($camelCase));
        self::assertSame($normalizer->returnAmount($snakeCase), $normalizer->returnAmount($camelCase));
        self::assertSame($normalizer->deliveryService($snakeCase), $normalizer->deliveryService($camelCase));
        self::assertSame($normalizer->paidStorage($snakeCase), $normalizer->paidStorage($camelCase));
        self::assertSame($normalizer->paidAcceptance($snakeCase), $normalizer->paidAcceptance($camelCase));
        self::assertSame($normalizer->rebillLogisticCost($snakeCase), $normalizer->rebillLogisticCost($camelCase));
        self::assertSame($normalizer->bonusTypeName($snakeCase), $normalizer->bonusTypeName($camelCase));
        self::assertSame($normalizer->ppvzReward($snakeCase), $normalizer->ppvzReward($camelCase));
        self::assertSame($normalizer->cashbackDiscount($snakeCase), $normalizer->cashbackDiscount($camelCase));
        self::assertSame(
            $normalizer->operationDate($snakeCase)->format('Y-m-d H:i:s'),
            $normalizer->operationDate($camelCase)->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            $normalizer->reportDate($snakeCase)->format('Y-m-d H:i:s'),
            $normalizer->reportDate($camelCase)->format('Y-m-d H:i:s'),
        );
    }

    public function testStringFallbackSkipsEmptyCamelCaseAndUsesSnakeCase(): void
    {
        $normalizer = new WbSalesReportRowNormalizer();

        $row = [
            'sellerOperName' => '',
            'supplier_oper_name' => 'Продажа',
        ];

        self::assertSame('Продажа', $normalizer->sellerOperName($row));
    }

    public function testNullableStringFallbackSkipsEmptyCamelCaseAndUsesSnakeCase(): void
    {
        $normalizer = new WbSalesReportRowNormalizer();

        $row = [
            'techSize' => '',
            'ts_name' => 'M',
        ];

        self::assertSame('M', $normalizer->techSize($row));
    }

    public function testFloatFallbackSkipsEmptyCamelCaseAndUsesSnakeCase(): void
    {
        $normalizer = new WbSalesReportRowNormalizer();

        $row = [
            'retailPriceWithDisc' => '',
            'retail_price_withdisc_rub' => '1300.40',
        ];

        self::assertSame(1300.40, $normalizer->retailPriceWithDisc($row));
    }

    public function testFloatReturnsZeroWhenAllFallbackValuesAreEmpty(): void
    {
        $normalizer = new WbSalesReportRowNormalizer();

        $row = [
            'paidStorage' => '',
            'storage_fee' => null,
        ];

        self::assertSame(0.0, $normalizer->paidStorage($row));
    }

    public function testOperationDateFallbackSkipsEmptySaleDtAndUsesRrDate(): void
    {
        $normalizer = new WbSalesReportRowNormalizer();

        $row = [
            'saleDt' => '',
            'rrDate' => '2026-05-01',
        ];

        self::assertSame('2026-05-01', $normalizer->operationDate($row)->format('Y-m-d'));
    }
}
