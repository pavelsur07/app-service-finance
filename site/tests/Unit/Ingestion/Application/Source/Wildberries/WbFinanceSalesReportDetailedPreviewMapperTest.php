<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\Source\Wildberries\WbFinancePreviewTransaction;
use App\Ingestion\Application\Source\Wildberries\WbFinanceSalesReportDetailedPreviewMapper;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use PHPUnit\Framework\TestCase;

final class WbFinanceSalesReportDetailedPreviewMapperTest extends TestCase
{
    private const COMPANY_ID = '19621cff-b028-45d9-9193-11f47ad9a8b2';

    public function testMapsSaleRowToSellerPriceCommissionAndAcquiring(): void
    {
        $result = $this->mapper()->preview(self::COMPANY_ID, [[
            'rrdId' => 101,
            'currency' => 'RUB',
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Продажа',
            'quantity' => 1,
            'saleDt' => '2026-06-21T10:15:00Z',
            'retailPriceWithDisc' => '1000.00',
            'retailAmount' => '920.00',
            'forPay' => '850.00',
            'acquiringFee' => '20.00',
            'ppvzVw' => '40.00',
            'ppvzVwNds' => '10.00',
            'srid' => 'sale-srid',
            'nmId' => 123,
            'sku' => 'sku-1',
        ]]);

        self::assertCount(3, $result->transactions);

        $sale = $this->transaction($result->transactions, 'wb:sales-report-detailed:101:sale');
        self::assertSame(TransactionType::SALE, $sale->type);
        self::assertSame(TransactionDirection::IN, $sale->direction);
        self::assertSame(100000, $sale->amountMinor);
        self::assertSame('retailPriceWithDisc*quantity', $sale->field);
        self::assertSame(3, $sale->sourceData['_ingestion_mapper_version']);
        self::assertSame('2026-06-21 10:15:00', $sale->occurredAt->format('Y-m-d H:i:s'));

        $commission = $this->transaction($result->transactions, 'wb:sales-report-detailed:101:commission');
        self::assertSame(TransactionType::COMMISSION, $commission->type);
        self::assertSame(TransactionDirection::OUT, $commission->direction);
        self::assertSame(13000, $commission->amountMinor);
        self::assertSame('retailPriceWithDisc*quantity-forPay-acquiringFee', $commission->field);

        $acquiring = $this->transaction($result->transactions, 'wb:sales-report-detailed:101:acquiring');
        self::assertSame(TransactionType::ACQUIRING, $acquiring->type);
        self::assertSame(TransactionDirection::OUT, $acquiring->direction);
        self::assertSame(2000, $acquiring->amountMinor);

        self::assertCount(1, $result->rowChecks);
        self::assertSame(85000, $result->rowChecks[0]->expectedNetMinor);
        self::assertSame(85000, $result->rowChecks[0]->actualNetMinor);
        self::assertSame(0, $result->rowChecks[0]->deltaMinor);
    }

    public function testPayoutCheckIgnoresAuxiliaryOperationsOnSaleRow(): void
    {
        $result = $this->mapper()->preview(self::COMPANY_ID, [[
            'rrdId' => 107,
            'currency' => 'RUB',
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Продажа',
            'quantity' => 1,
            'saleDt' => '2026-06-21T10:15:00Z',
            'retailPriceWithDisc' => '1000.00',
            'retailAmount' => '920.00',
            'forPay' => '850.00',
            'acquiringFee' => '20.00',
            'ppvzVw' => '40.00',
            'ppvzVwNds' => '10.00',
            'deliveryAmount' => 1,
            'deliveryService' => '-50.00',
            'ppvzReward' => '-17.25',
            'cashbackDiscount' => '4.10',
        ]]);

        self::assertCount(5, $result->transactions);
        $this->transaction($result->transactions, 'wb:sales-report-detailed:107:logistics_delivery');
        $this->transaction($result->transactions, 'wb:sales-report-detailed:107:loyalty_discount_compensation');
        self::assertNotContains(
            'wb:sales-report-detailed:107:pvz_processing',
            array_map(static fn (WbFinancePreviewTransaction $transaction): string => $transaction->sourceKey, $result->transactions),
        );

        self::assertCount(1, $result->rowChecks);
        self::assertSame(85000, $result->rowChecks[0]->expectedNetMinor);
        self::assertSame(85000, $result->rowChecks[0]->actualNetMinor);
        self::assertSame(0, $result->rowChecks[0]->deltaMinor);
        self::assertSame(3, $result->rowChecks[0]->transactionCount);
    }

    public function testMapsSalePayoutAdjustmentsWithoutFlippingForPaySign(): void
    {
        foreach ([
            ['rrdId' => 108, 'sellerOperName' => 'Коррекция продаж', 'forPay' => '12.77', 'amountMinor' => 1277],
            ['rrdId' => 109, 'sellerOperName' => 'Добровольная компенсация при возврате', 'forPay' => '197.22', 'amountMinor' => 19722],
        ] as $case) {
            $result = $this->mapper()->preview(self::COMPANY_ID, [[
                'rrdId' => $case['rrdId'],
                'currency' => 'RUB',
                'docTypeName' => 'Продажа',
                'sellerOperName' => $case['sellerOperName'],
                'saleDt' => '2026-06-21T10:15:00Z',
                'retailPriceWithDisc' => '0',
                'forPay' => $case['forPay'],
                'acquiringFee' => '0',
            ]]);

            self::assertCount(1, $result->transactions);

            $transaction = $this->transaction($result->transactions, sprintf('wb:sales-report-detailed:%d:sale_payout_adjustment', $case['rrdId']));
            self::assertSame(TransactionType::ADJUSTMENT, $transaction->type);
            self::assertSame(TransactionDirection::IN, $transaction->direction);
            self::assertSame($case['amountMinor'], $transaction->amountMinor);
            self::assertSame('forPay', $transaction->field);

            self::assertCount(1, $result->rowChecks);
            self::assertSame($case['amountMinor'], $result->rowChecks[0]->expectedNetMinor);
            self::assertSame($case['amountMinor'], $result->rowChecks[0]->actualNetMinor);
            self::assertSame(0, $result->rowChecks[0]->deltaMinor);
            self::assertSame(1, $result->rowChecks[0]->transactionCount);
        }
    }

    public function testMapsReturnRowAsOutboundRefundWithCommissionAndAcquiringStorno(): void
    {
        $result = $this->mapper()->preview(self::COMPANY_ID, [[
            'rrdId' => 102,
            'currency' => 'RUB',
            'docTypeName' => 'Возврат',
            'sellerOperName' => 'Возврат',
            'quantity' => 1,
            'saleDt' => '2026-06-21T12:00:00Z',
            'retailPriceWithDisc' => '1000.00',
            'retailAmount' => '920.00',
            'forPay' => '-850.00',
            'acquiringFee' => '-20.00',
            'ppvzVw' => '40.00',
            'ppvzVwNds' => '10.00',
        ]]);

        self::assertCount(3, $result->transactions);

        $refund = $this->transaction($result->transactions, 'wb:sales-report-detailed:102:refund');
        self::assertSame(TransactionType::REFUND, $refund->type);
        self::assertSame(TransactionDirection::OUT, $refund->direction);
        self::assertSame(100000, $refund->amountMinor);

        $commission = $this->transaction($result->transactions, 'wb:sales-report-detailed:102:commission');
        self::assertSame(TransactionType::COMMISSION, $commission->type);
        self::assertSame(TransactionDirection::IN, $commission->direction);
        self::assertSame(13000, $commission->amountMinor);

        $acquiring = $this->transaction($result->transactions, 'wb:sales-report-detailed:102:acquiring');
        self::assertSame(TransactionType::ACQUIRING, $acquiring->type);
        self::assertSame(TransactionDirection::IN, $acquiring->direction);
        self::assertSame(2000, $acquiring->amountMinor);

        self::assertCount(1, $result->rowChecks);
        self::assertSame(-85000, $result->rowChecks[0]->expectedNetMinor);
        self::assertSame(-85000, $result->rowChecks[0]->actualNetMinor);
        self::assertSame(0, $result->rowChecks[0]->deltaMinor);
    }

    public function testUsesCanonicalFormulaInsteadOfVwFields(): void
    {
        $result = $this->mapper()->preview(self::COMPANY_ID, [[
            'rrdId' => 110,
            'currency' => 'RUB',
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Продажа',
            'quantity' => 1,
            'saleDt' => '2026-03-21T00:00:00Z',
            'retailPriceWithDisc' => '399.68',
            'retailAmount' => '367.00',
            'forPay' => '376.99',
            'acquiringFee' => '14.89',
            'vw' => '22.25',
            'vwNds' => '4.45',
        ]]);

        self::assertSame(39968, $this->transaction($result->transactions, 'wb:sales-report-detailed:110:sale')->amountMinor);
        self::assertSame(780, $this->transaction($result->transactions, 'wb:sales-report-detailed:110:commission')->amountMinor);
        self::assertSame(0, $result->rowChecks[0]->deltaMinor);
    }

    public function testMapsCostFieldsWithAccountingDirectionsAndRounding(): void
    {
        $result = $this->mapper()->preview(self::COMPANY_ID, [[
            'rrdId' => 103,
            'currency' => 'RUB',
            'sellerOperName' => 'Логистика',
            'rrDate' => '2026-06-21',
            'deliveryAmount' => 1,
            'deliveryService' => '-149.03',
            'paidStorage' => '-10.00',
            'paidAcceptance' => '3.335',
            'penalty' => '-5.00',
            'deduction' => '-7',
            'rebillLogisticCost' => '-1.349',
            'additionalPayment' => '2.50',
        ]]);

        self::assertCount(7, $result->transactions);

        $logistics = $this->transaction($result->transactions, 'wb:sales-report-detailed:103:logistics_delivery');
        self::assertSame(TransactionType::LOGISTICS, $logistics->type);
        self::assertSame(TransactionDirection::OUT, $logistics->direction);
        self::assertSame(14903, $logistics->amountMinor);

        $storage = $this->transaction($result->transactions, 'wb:sales-report-detailed:103:storage');
        self::assertSame(TransactionDirection::OUT, $storage->direction);
        self::assertSame(1000, $storage->amountMinor);

        $acceptance = $this->transaction($result->transactions, 'wb:sales-report-detailed:103:acceptance');
        self::assertSame(TransactionDirection::OUT, $acceptance->direction);
        self::assertSame(334, $acceptance->amountMinor);

        $deduction = $this->transaction($result->transactions, 'wb:sales-report-detailed:103:deduction');
        self::assertSame(TransactionDirection::OUT, $deduction->direction);
        self::assertSame(700, $deduction->amountMinor);

        $warehouseLogistics = $this->transaction($result->transactions, 'wb:sales-report-detailed:103:warehouse_logistics');
        self::assertSame(TransactionDirection::OUT, $warehouseLogistics->direction);
        self::assertSame(135, $warehouseLogistics->amountMinor);

        $penalty = $this->transaction($result->transactions, 'wb:sales-report-detailed:103:penalty');
        self::assertSame(TransactionType::PENALTY, $penalty->type);
        self::assertSame(TransactionDirection::OUT, $penalty->direction);
        self::assertSame(500, $penalty->amountMinor);

        $additionalPayment = $this->transaction($result->transactions, 'wb:sales-report-detailed:103:additional_payment');
        self::assertSame(TransactionType::BONUS, $additionalPayment->type);
        self::assertSame(TransactionDirection::IN, $additionalPayment->direction);
        self::assertSame(250, $additionalPayment->amountMinor);
    }

    public function testMapsPvzProcessingRewardAsLogisticsExpense(): void
    {
        $result = $this->mapper()->preview(self::COMPANY_ID, [[
            'rrdId' => 104,
            'currency' => 'RUB',
            'sellerOperName' => 'Возмещение за выдачу и возврат товаров на ПВЗ',
            'docTypeName' => 'Продажа',
            'rrDate' => '2026-06-21',
            'ppvzReward' => '-17.25',
            'vw' => '-14.38',
            'vwNds' => '-2.87',
        ]]);

        self::assertCount(1, $result->transactions);
        self::assertSame([], $result->rowChecks);
        self::assertSame([], $result->unknownRows);
        self::assertSame([], $result->validationIssues);

        $transaction = $this->transaction($result->transactions, 'wb:sales-report-detailed:104:pvz_processing');
        self::assertSame(TransactionType::LOGISTICS, $transaction->type);
        self::assertSame(TransactionDirection::OUT, $transaction->direction);
        self::assertSame(1725, $transaction->amountMinor);
        self::assertSame('ppvzReward', $transaction->field);
        self::assertSame('-17.25', $transaction->sourceData['ppvzReward']);
    }

    public function testMapsLoyaltyDiscountCompensationAsBonusIncome(): void
    {
        $result = $this->mapper()->preview(self::COMPANY_ID, [[
            'rrdId' => 105,
            'currency' => 'RUB',
            'sellerOperName' => 'Компенсация скидки по программе лояльности',
            'docTypeName' => 'Продажа',
            'rrDate' => '2026-06-21',
            'cashbackDiscount' => '42.10',
        ]]);

        self::assertCount(1, $result->transactions);
        self::assertSame([], $result->rowChecks);
        self::assertSame([], $result->unknownRows);
        self::assertSame([], $result->validationIssues);

        $transaction = $this->transaction($result->transactions, 'wb:sales-report-detailed:105:loyalty_discount_compensation');
        self::assertSame(TransactionType::BONUS, $transaction->type);
        self::assertSame(TransactionDirection::IN, $transaction->direction);
        self::assertSame(4210, $transaction->amountMinor);
        self::assertSame('cashbackDiscount', $transaction->field);
        self::assertSame('42.10', $transaction->sourceData['cashbackDiscount']);
    }

    public function testReportsUnknownRowsWithNoMappedTransactions(): void
    {
        $result = $this->mapper()->preview(self::COMPANY_ID, [[
            'rrdId' => 106,
            'sellerOperName' => 'Новая операция',
            'rrDate' => '2026-06-21',
            'forPay' => '12.34',
            'loyaltyDiscount' => '5.67',
        ]]);

        self::assertSame([], $result->transactions);
        self::assertCount(1, $result->unknownRows);
        self::assertSame('106', $result->unknownRows[0]->rowKey);
        self::assertSame('Новая операция', $result->unknownRows[0]->sellerOperName);
        self::assertSame(['forPay', 'loyaltyDiscount'], $result->unknownRows[0]->nonZeroFields);
    }

    /**
     * @param list<WbFinancePreviewTransaction> $transactions
     */
    private function transaction(array $transactions, string $sourceKey): WbFinancePreviewTransaction
    {
        foreach ($transactions as $transaction) {
            if ($sourceKey === $transaction->sourceKey) {
                return $transaction;
            }
        }

        self::fail(sprintf('Transaction "%s" was not mapped.', $sourceKey));
    }

    private function mapper(): WbFinanceSalesReportDetailedPreviewMapper
    {
        return new WbFinanceSalesReportDetailedPreviewMapper();
    }
}
