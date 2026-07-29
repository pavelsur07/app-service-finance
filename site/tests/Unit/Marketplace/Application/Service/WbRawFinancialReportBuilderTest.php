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
                    'deduction' => '-99',
                    'bonusTypeName' => 'Удержание другого отчёта',
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
        self::assertSame([], $report['deductions']);
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

    public function testKeepsNegativeLogisticsAsExpenseAndTreatsNegativeDeductionAsPayment(): void
    {
        $report = (new WbRawFinancialReportBuilder())->build(
            [$this->document([[
                'reportId' => 705,
                'rrdId' => 6001,
                'sellerOperName' => 'Логистика',
                'deliveryService' => '-50',
                'deliveryAmount' => 1,
                'deduction' => '-5',
                'bonusTypeName' => 'Списание за отзыв',
            ]])],
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-01'),
        );

        self::assertSame(-4500, $report['summary']['payout_minor']);
        self::assertSame(4500, $report['summary']['wb_costs_minor']);
        self::assertSame(5000, $this->article($report, 'logistics_delivery')['accrual_minor']);
        self::assertSame(0, $this->article($report, 'deduction')['accrual_minor']);
        self::assertSame(500, $this->article($report, 'deduction')['reversal_minor']);
        self::assertSame(500, $this->article($report, 'deduction')['net_minor']);
        self::assertSame('Списание за отзыв', $report['deductions'][0]['reason']);
        self::assertSame(0, $report['deductions'][0]['withheld_minor']);
        self::assertSame(500, $report['deductions'][0]['paid_minor']);
        self::assertSame(500, $report['deductions'][0]['impact_minor']);
    }

    public function testBreaksDeductionsDownByExactRawReason(): void
    {
        $compensationReason = 'Добровольная выплата за товары, пострадавшие в результате '
            .'обстоятельств непреодолимой силы, документ №123';
        $report = (new WbRawFinancialReportBuilder())->build(
            [
                $this->document([
                    [
                        'reportId' => 801,
                        'rrdId' => 6101,
                        'deduction' => '-7.50',
                        'bonusTypeName' => $compensationReason,
                    ],
                    [
                        'reportId' => 802,
                        'rrdId' => 6102,
                        'deduction' => '2.50',
                        'bonus_type_name' => $compensationReason,
                    ],
                    [
                        'reportId' => 802,
                        'rrdId' => 6103,
                        'deduction' => '-3',
                    ],
                ]),
                $this->document([[
                    'reportId' => 801,
                    'rrdId' => 6104,
                    'deduction' => '-1.25',
                    'bonusTypeName' => $compensationReason,
                ]], businessDate: '2026-07-02'),
            ],
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-02'),
        );

        self::assertCount(2, $report['deductions']);
        self::assertSame([
            'reason' => $compensationReason,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
            'row_count' => 3,
            'withheld_minor' => 250,
            'paid_minor' => 875,
            'impact_minor' => 625,
            'report_count' => 2,
        ], $report['deductions'][0]);
        self::assertSame('Без расшифровки WB', $report['deductions'][1]['reason']);
        self::assertSame(0, $report['deductions'][1]['withheld_minor']);
        self::assertSame(300, $report['deductions'][1]['paid_minor']);
        self::assertSame(300, $report['deductions'][1]['impact_minor']);
        self::assertSame(250, $this->article($report, 'deduction')['accrual_minor']);
        self::assertSame(1175, $this->article($report, 'deduction')['reversal_minor']);
        self::assertSame(925, $this->article($report, 'deduction')['net_minor']);
        self::assertSame(925, $report['summary']['payout_minor']);
        self::assertSame(-925, $report['summary']['wb_costs_minor']);
        self::assertSame(250, $report['summary']['deduction_withheld_minor']);
        self::assertSame(1175, $report['summary']['deduction_paid_minor']);
        self::assertSame(925, $report['summary']['deduction_impact_minor']);
        self::assertSame('801', $report['reports'][0]['report_id']);
        self::assertSame(875, $report['reports'][0]['payout_minor']);
        self::assertSame('802', $report['reports'][1]['report_id']);
        self::assertSame(50, $report['reports'][1]['payout_minor']);
        self::assertSame(925, $report['operations'][0]['payout_minor']);
        self::assertSame(
            $this->article($report, 'deduction')['net_minor'],
            array_sum(array_column($report['deductions'], 'impact_minor')),
        );
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
    private function document(
        array $rows,
        ?int $recordsCount = null,
        string $businessDate = '2026-07-01',
    ): array {
        return [
            'business_date' => $businessDate,
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
