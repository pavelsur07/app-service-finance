<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Application\Source\Wildberries\WbFinanceSalesReportDetailedMapper;
use App\Ingestion\Application\Source\Wildberries\WbFinanceSalesReportDetailedPreviewMapper;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
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

        self::assertCount(4, $transactions);

        $sale = $this->transaction($transactions, 'wb:sales-report-detailed:101:sale');
        self::assertSame(TransactionType::SALE, $sale->type);
        self::assertSame(TransactionDirection::IN, $sale->direction);
        self::assertSame(92000, $sale->money->amountMinor());
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

        $spp = $this->transaction($transactions, 'wb:sales-report-detailed:101:spp_compensation');
        self::assertSame(TransactionType::BONUS, $spp->type);
        self::assertSame(TransactionDirection::IN, $spp->direction);
        self::assertSame(8000, $spp->money->amountMinor());

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
            'quantity' => 1,
            'saleDt' => '2026-06-21T10:15:00Z',
            'retailPriceWithDisc' => '1000.00',
            'retailAmount' => '920.00',
            'forPay' => '850.00',
            'acquiringFee' => '20.00',
            'ppvzVw' => '40.00',
            'ppvzVwNds' => '10.00',
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

    public function testNegativeCommissionMapsWithReverseDirectionWithoutPreviewIssue(): void
    {
        $rawRecord = $this->rawRecord();
        $rows = [[
            'rrdId' => 102,
            'currency' => 'RUB',
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Продажа',
            'quantity' => 1,
            'saleDt' => '2026-06-21T10:15:00Z',
            'retailPriceWithDisc' => '1000.00',
            'retailAmount' => '1000.00',
            'forPay' => '1100.00',
            'acquiringFee' => '0',
            'ppvzVw' => '0',
            'ppvzVwNds' => '0',
        ]];

        $transactions = $this->mapper()->map($rawRecord, $rows);
        self::assertCount(2, $transactions);
        self::assertSame(100000, $this->transaction($transactions, 'wb:sales-report-detailed:102:sale')->money->amountMinor());
        $commission = $this->transaction($transactions, 'wb:sales-report-detailed:102:commission');
        self::assertSame(TransactionDirection::IN, $commission->direction);
        self::assertSame(10000, $commission->money->amountMinor());

        self::assertSame([], $this->mapper()->previewIssues($rawRecord, $rows));
    }

    public function testUnknownRowsWithNonZeroAmountsStillMapAndAreReportedAsPreviewIssue(): void
    {
        $rawRecord = $this->rawRecord();
        $rows = [[
            'rrdId' => 103,
            'sellerOperName' => 'Новая операция',
            'rrDate' => '2026-06-21',
            'forPay' => '12.34',
        ]];

        self::assertSame([], $this->mapper()->map($rawRecord, $rows));

        $issues = $this->mapper()->previewIssues($rawRecord, $rows);
        self::assertCount(1, $issues);
        self::assertSame(NormalizationIssueKind::UNKNOWN_FIELD, $issues[0]->kind);
        self::assertNull($issues[0]->operationGroupId);
        self::assertSame('wb_unknown_row', $issues[0]->details['check']);
        self::assertSame('103', $issues[0]->details['rowKey']);
        self::assertSame('Новая операция', $issues[0]->details['sellerOperName']);
        self::assertSame(['forPay'], $issues[0]->details['nonZeroFields']);
    }

    public function testPreviewIssuesAreEmptyForConsistentRows(): void
    {
        self::assertSame([], $this->mapper()->previewIssues($this->rawRecord(), [[
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
        ]]));
    }

    public function testInvalidCanonicalInputsAreReportedEvenWhenPayoutIdentityBalances(): void
    {
        $issues = $this->mapper()->previewIssues($this->rawRecord(), [[
            'rrdId' => 111,
            'currency' => 'RUB',
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Продажа',
            'quantity' => 'not-a-number',
            'saleDt' => '2026-06-21T10:15:00Z',
            'retailPriceWithDisc' => '0',
            'retailAmount' => '920.00',
            'forPay' => '850.00',
            'acquiringFee' => '20.00',
        ]]);

        self::assertCount(2, $issues);
        self::assertSame(['quantity', 'retailPriceWithDisc'], array_column(array_map(
            static fn ($issue): array => $issue->details,
            $issues,
        ), 'field'));
        foreach ($issues as $issue) {
            self::assertSame(NormalizationIssueKind::UNKNOWN_FIELD, $issue->kind);
            self::assertSame('wb_invalid_canonical_input', $issue->details['check']);
            self::assertNotNull($issue->operationGroupId);
        }
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
