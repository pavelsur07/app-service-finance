<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Application\Source\Wildberries\WbFinanceSalesReportDetailedMapper;
use App\Ingestion\Application\Source\Wildberries\WbFinanceSalesReportDetailedPreviewMapper;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class WbFinanceSalesReportDetailedMapperTest extends TestCase
{
    private const COMPANY_ID = '19621cff-b028-45d9-9193-11f47ad9a8b2';

    public function testMapsPreviewTransactionsToCanonicalTransactions(): void
    {
        $rawRecord = $this->rawRecord();

        $transactions = $this->mapper()->map($rawRecord, [[
            'rrdId' => 101,
            'reportId' => 42880202606211,
            'currency' => 'RUB',
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Продажа',
            'saleDt' => '2026-06-21T10:15:00Z',
            'retailPriceWithDisc' => '1000.00',
            'forPay' => '850.00',
            'acquiringFee' => '20.00',
            'srid' => 'sale-srid',
            'nmId' => 123,
            'sku' => 'sku-1',
        ]]);

        self::assertCount(3, $transactions);

        $sale = $this->transaction($transactions, 'wb:sales-report-detailed:101:sale');
        self::assertSame(TransactionType::SALE, $sale->type);
        self::assertSame(TransactionDirection::IN, $sale->direction);
        self::assertSame(100000, $sale->money->amountMinor());
        self::assertSame('2026-06-21 10:15:00', $sale->occurredAt->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $sale->sourceTz);
        self::assertSame($rawRecord->getFetchedAt(), $sale->externalUpdatedAt);
        self::assertSame('sale-srid', $sale->orderRef);
        self::assertSame('42880202606211', $sale->payoutRef);
        self::assertSame(WbResourceType::FINANCE_SALES_REPORT_DETAILED, $sale->sourceData['_ingestion_resource']);
        self::assertSame('sale', $sale->sourceData['_ingestion_component']);
        self::assertSame('123', $sale->sourceData['nmId']);
        self::assertSame('sku-1', $sale->sourceData['sku']);

        $commission = $this->transaction($transactions, 'wb:sales-report-detailed:101:commission');
        self::assertSame(TransactionType::COMMISSION, $commission->type);
        self::assertSame(TransactionDirection::OUT, $commission->direction);
        self::assertSame(13000, $commission->money->amountMinor());

        $acquiring = $this->transaction($transactions, 'wb:sales-report-detailed:101:acquiring');
        self::assertSame(TransactionType::ACQUIRING, $acquiring->type);
        self::assertSame(TransactionDirection::OUT, $acquiring->direction);
        self::assertSame(2000, $acquiring->money->amountMinor());
    }

    public function testBuildsTechnicalControlSumsFromMappedAmounts(): void
    {
        $controlSums = $this->mapper()->controlSumForRawRecord($this->rawRecord(), [[
            'rrdId' => 101,
            'currency' => 'RUB',
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Продажа',
            'saleDt' => '2026-06-21T10:15:00Z',
            'retailPriceWithDisc' => '1000.00',
            'forPay' => '850.00',
            'acquiringFee' => '20.00',
        ]]);

        self::assertCount(1, $controlSums);
        self::assertSame('RUB', $controlSums[0]->currency);
        self::assertSame(115000, $controlSums[0]->amountMinor);
    }

    public function testMapsSalePayoutAdjustmentRowsWithoutPayoutMismatch(): void
    {
        $transactions = $this->mapper()->map($this->rawRecord(), [
            [
                'rrdId' => 201,
                'currency' => 'RUB',
                'docTypeName' => 'Продажа',
                'sellerOperName' => 'Коррекция продаж',
                'saleDt' => '2026-06-10T10:15:00Z',
                'retailPriceWithDisc' => '0',
                'forPay' => '12.77',
                'acquiringFee' => '0',
            ],
            [
                'rrdId' => 202,
                'currency' => 'RUB',
                'docTypeName' => 'Продажа',
                'sellerOperName' => 'Добровольная компенсация при возврате',
                'saleDt' => '2026-06-17T10:15:00Z',
                'retailPriceWithDisc' => '0',
                'forPay' => '197.22',
                'acquiringFee' => '0',
            ],
        ]);

        self::assertCount(2, $transactions);

        $correction = $this->transaction($transactions, 'wb:sales-report-detailed:201:sale_payout_adjustment');
        self::assertSame(TransactionType::ADJUSTMENT, $correction->type);
        self::assertSame(TransactionDirection::IN, $correction->direction);
        self::assertSame(1277, $correction->money->amountMinor());

        $compensation = $this->transaction($transactions, 'wb:sales-report-detailed:202:sale_payout_adjustment');
        self::assertSame(TransactionType::ADJUSTMENT, $compensation->type);
        self::assertSame(TransactionDirection::IN, $compensation->direction);
        self::assertSame(19722, $compensation->money->amountMinor());
    }

    public function testRejectsPayoutMismatches(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WB finance payout check mismatch');

        $this->mapper()->map($this->rawRecord(), [[
            'rrdId' => 102,
            'currency' => 'RUB',
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Продажа',
            'saleDt' => '2026-06-21T10:15:00Z',
            'retailPriceWithDisc' => '1000.00',
            'forPay' => '1100.00',
            'acquiringFee' => '0',
        ]]);
    }

    public function testRejectsUnknownRowsWithNonZeroKnownAmounts(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unmapped non-zero fields');

        $this->mapper()->map($this->rawRecord(), [[
            'rrdId' => 103,
            'sellerOperName' => 'Новая операция',
            'rrDate' => '2026-06-21',
            'forPay' => '12.34',
        ]]);
    }

    /**
     * @param list<MappedTransaction> $transactions
     */
    private function transaction(array $transactions, string $externalId): MappedTransaction
    {
        foreach ($transactions as $transaction) {
            if ($externalId === $transaction->externalId) {
                return $transaction;
            }
        }

        self::fail(sprintf('Mapped transaction with external id "%s" was not found.', $externalId));
    }

    private function mapper(): WbFinanceSalesReportDetailedMapper
    {
        return new WbFinanceSalesReportDetailedMapper(new WbFinanceSalesReportDetailedPreviewMapper());
    }

    private function rawRecord(): IngestRawRecord
    {
        return new IngestRawRecord(
            companyId: self::COMPANY_ID,
            connectionRef: Uuid::uuid7()->toString(),
            shopRef: Uuid::uuid7()->toString(),
            source: IngestSource::WILDBERRIES,
            resourceType: WbResourceType::FINANCE_SALES_REPORT_DETAILED,
            externalId: 'wb-sales-report-detailed:2026-06-21:rrd-0',
            storagePath: 'raw.ndjson.gz',
            hash: str_repeat('a', 64),
            byteSize: 100,
            fetchedAt: new \DateTimeImmutable('2026-06-22 09:17:43+00:00'),
            syncJobId: Uuid::uuid7()->toString(),
        );
    }
}
