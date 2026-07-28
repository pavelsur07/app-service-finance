<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\WbRawFinancialReportBuilder;
use PHPUnit\Framework\TestCase;

final class WbRawFinancialReportBuilderTest extends TestCase
{
    public function testBuildsExactSaleTotalsWithoutSubtractingCommissionTwice(): void
    {
        $report = (new WbRawFinancialReportBuilder())->build(
            [$this->document([[
                'reportId' => 701,
                'rrdId' => 1001,
                'docTypeName' => 'Продажа',
                'sellerOperName' => 'Продажа',
                'quantity' => 1,
                'retailPriceWithDisc' => '2099',
                'retailAmount' => '1584',
                'forPay' => '1308.04',
                'acquiringFee' => '77.30',
            ]])],
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-01'),
        );

        self::assertSame(209900, $report['summary']['sale_without_spp_minor']);
        self::assertSame(158400, $report['summary']['sale_with_spp_minor']);
        self::assertSame(130804, $report['summary']['for_pay_minor']);
        self::assertSame(130804, $report['summary']['payout_minor']);
        self::assertSame(79096, $report['summary']['wb_costs_minor']);
        self::assertSame(71366, $this->article($report, 'commission')['accrual_minor']);
        self::assertSame(7730, $this->article($report, 'acquiring')['accrual_minor']);
        self::assertArrayNotHasKey('report_ids', $report['operations'][0]);
    }

    public function testNetsReturnsAndOtherSettlements(): void
    {
        $productRow = [
            'reportId' => '702',
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Продажа',
            'quantity' => 1,
            'retailPriceWithDisc' => '100',
            'retailAmount' => '90',
            'forPay' => '75',
            'acquiringFee' => '2',
        ];

        $report = (new WbRawFinancialReportBuilder())->build(
            [$this->document([
                ['rrdId' => 2001, ...$productRow],
                ['rrdId' => 2002, ...$productRow, 'docTypeName' => 'Возврат', 'sellerOperName' => 'Возврат'],
                [
                    'reportId' => '702',
                    'rrdId' => 2003,
                    'sellerOperName' => 'Логистика',
                    'deliveryService' => '80',
                    'deliveryAmount' => 1,
                ],
                [
                    'reportId' => '702',
                    'rrdId' => 2004,
                    'sellerOperName' => 'Доплата',
                    'additionalPayment' => '10',
                ],
            ])],
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-01'),
        );

        self::assertSame(0, $report['summary']['sale_without_spp_minor']);
        self::assertSame(0, $report['summary']['for_pay_minor']);
        self::assertSame(-7000, $report['summary']['payout_minor']);
        self::assertSame(8000, $report['summary']['wb_costs_minor']);
        self::assertSame(4, $report['summary']['row_count']);
        self::assertSame(-7000, $report['reports'][0]['payout_minor']);
    }

    public function testReportsQualityIssuesAndSupportsReportIdFilter(): void
    {
        $report = (new WbRawFinancialReportBuilder())->build(
            [$this->document([
                [
                    'reportId' => 'wanted',
                    'rrdId' => 3001,
                    'docTypeName' => 'Продажа',
                    'sellerOperName' => 'Продажа',
                    'quantity' => 'broken',
                    'retailPriceWithDisc' => 'not-money',
                    'retailAmount' => '10',
                    'forPay' => '5',
                ],
                [
                    'reportId' => 'wanted',
                    'rrdId' => 3001,
                    'cashbackAmount' => '1',
                ],
                [
                    'reportId' => 'other',
                    'rrdId' => 3002,
                    'retailAmount' => '999',
                ],
            ], recordsCount: 4)],
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-02'),
            'wanted',
        );

        self::assertSame(2, $report['summary']['row_count']);
        self::assertSame(1, $report['quality']['duplicate_rrd_rows']);
        self::assertSame(1, $report['quality']['invalid_money_values']);
        self::assertSame(1, $report['quality']['invalid_quantity_rows']);
        self::assertSame(1, $report['quality']['excluded_product_rows']);
        self::assertSame(1, $report['quality']['unclassified_money_rows']);
        self::assertSame(1, $report['quality']['row_count_mismatches']);
        self::assertSame(1, $report['coverage']['status_counts']['missing']);
        self::assertSame(0, $this->article($report, 'commission')['net_minor']);
        self::assertSame(0, $report['summary']['payout_minor']);
    }

    public function testMalformedExtremeQuantityDoesNotBreakReport(): void
    {
        $report = (new WbRawFinancialReportBuilder())->build(
            [$this->document([[
                'reportId' => 703,
                'rrdId' => 4001,
                'docTypeName' => 'Продажа',
                'sellerOperName' => 'Продажа',
                'quantity' => '999999999999999999999999999999',
                'retailPriceWithDisc' => '100',
                'retailAmount' => '90',
                'forPay' => '75',
                'acquiringFee' => '2',
            ]])],
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-01'),
        );

        self::assertSame(1, $report['quality']['invalid_quantity_rows']);
        self::assertSame(1, $report['quality']['excluded_product_rows']);
        self::assertSame(1, $report['summary']['row_count']);
        self::assertSame(0, $report['summary']['payout_minor']);
    }

    public function testKeepsRawSignForSalePayoutAdjustment(): void
    {
        $report = (new WbRawFinancialReportBuilder())->build(
            [$this->document([[
                'reportId' => 704,
                'rrdId' => 5001,
                'docTypeName' => 'Продажа',
                'sellerOperName' => 'Коррекция продаж',
                'quantity' => 0,
                'retailPriceWithDisc' => '0',
                'retailAmount' => '0',
                'forPay' => '-25',
                'acquiringFee' => '0',
            ]])],
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-01'),
        );

        self::assertSame(-2500, $report['summary']['for_pay_minor']);
        self::assertSame(-2500, $report['summary']['payout_minor']);
        self::assertSame(0, $report['quality']['excluded_product_rows']);
    }

    public function testNormalizesNegativeRawCostFieldsAsExpenses(): void
    {
        $report = (new WbRawFinancialReportBuilder())->build(
            [$this->document([[
                'reportId' => 705,
                'rrdId' => 6001,
                'sellerOperName' => 'Логистика',
                'deliveryService' => '-50',
                'deliveryAmount' => 1,
                'deduction' => '-5',
            ]])],
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-01'),
        );

        self::assertSame(-5500, $report['summary']['payout_minor']);
        self::assertSame(5500, $report['summary']['wb_costs_minor']);
        self::assertSame(5000, $this->article($report, 'logistics_delivery')['accrual_minor']);
        self::assertSame(500, $this->article($report, 'deduction')['accrual_minor']);
    }

    public function testFlagsUnsupportedProductDocumentTypeWithoutAddingItToPayout(): void
    {
        $report = (new WbRawFinancialReportBuilder())->build(
            [$this->document([[
                'reportId' => 706,
                'rrdId' => 7001,
                'docTypeName' => 'Сторно продаж',
                'sellerOperName' => 'Сторно продаж',
                'quantity' => 1,
                'retailPriceWithDisc' => '100',
                'retailAmount' => '90',
                'forPay' => '75',
                'acquiringFee' => '2',
            ]])],
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-01'),
        );

        self::assertSame(1, $report['quality']['unclassified_doc_type_rows']);
        self::assertSame(0, $report['summary']['payout_minor']);
    }

    public function testExcludesProductRowWhenGrossCalculationOverflows(): void
    {
        $report = (new WbRawFinancialReportBuilder())->build(
            [$this->document([[
                'reportId' => 707,
                'rrdId' => 8001,
                'docTypeName' => 'Продажа',
                'sellerOperName' => 'Продажа',
                'quantity' => (string) \PHP_INT_MAX,
                'retailPriceWithDisc' => '100',
                'retailAmount' => '90',
                'forPay' => '75',
                'acquiringFee' => '2',
            ]])],
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-01'),
        );

        self::assertSame(1, $report['quality']['invalid_money_values']);
        self::assertSame(1, $report['quality']['excluded_product_rows']);
        self::assertSame(0, $report['summary']['payout_minor']);
    }

    public function testDoesNotFlagNormalNonProductSaleDocumentAsInvalidProductRow(): void
    {
        $report = (new WbRawFinancialReportBuilder())->build(
            [$this->document([[
                'reportId' => 708,
                'rrdId' => 9001,
                'docTypeName' => 'Продажа',
                'sellerOperName' => 'Возмещение за выдачу и возврат товаров на ПВЗ',
                'quantity' => 0,
                'retailPriceWithDisc' => '0',
                'retailAmount' => '0',
                'forPay' => '0',
                'acquiringFee' => '0',
                'ppvzReward' => '-17.25',
            ]])],
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-01'),
        );

        self::assertSame(0, $report['quality']['invalid_quantity_rows']);
        self::assertSame(0, $report['quality']['excluded_product_rows']);
        self::assertSame(-1725, $report['summary']['payout_minor']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function document(array $rows, ?int $recordsCount = null): array
    {
        return [
            'business_date' => '2026-07-01',
            'status' => 'success',
            'records_count' => $recordsCount ?? count($rows),
            'raw_document_id' => '11111111-1111-4111-8111-111111111111',
            'joined_raw_document_id' => '11111111-1111-4111-8111-111111111111',
            'last_error_message' => null,
            'updated_at' => '2026-07-02 01:00:00',
            'raw_data' => $rows,
            'synced_at' => '2026-07-02 01:00:00',
        ];
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function article(array $report, string $key): array
    {
        foreach ($report['articles'] as $article) {
            if ($key === $article['key']) {
                return $article;
            }
        }

        self::fail(sprintf('Article "%s" not found.', $key));
    }
}
