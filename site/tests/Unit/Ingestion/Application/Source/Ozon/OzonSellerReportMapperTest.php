<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Source\Ozon\OzonMoneyParser;
use App\Ingestion\Application\Source\Ozon\OzonOperationKey;
use App\Ingestion\Application\Source\Ozon\OzonRealizationMapper;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Application\Source\Ozon\OzonSellerReportMapper;
use App\Ingestion\Application\Source\Ozon\OzonTransactionComponentMapper;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class OzonSellerReportMapperTest extends TestCase
{
    public function testDailyReportFixtureIsDecomposedIntoCanonicalTransactions(): void
    {
        $rows = $this->fixtureRows('transaction_list_with_sale_and_commission.json');
        $rawRecord = $this->rawRecord(OzonResourceType::DAILY_REPORT);
        $mapper = $this->dailyMapper();

        $transactions = $mapper->map($rawRecord, $rows);
        $controlSums = $mapper->controlSumForRawRecord($rawRecord, $rows);

        self::assertCount(6, $transactions);
        self::assertCount(1, $controlSums);
        self::assertSame(140855, $controlSums[0]->amountMinor);
        self::assertSame('RUB', $controlSums[0]->currency);

        $groupIds = array_unique(array_map(static fn ($transaction): string => $transaction->operationGroupId, $transactions));
        self::assertCount(1, $groupIds);
        self::assertSame($controlSums[0]->operationGroupId, $groupIds[0]);

        $byExternalId = [];
        foreach ($transactions as $transaction) {
            $byExternalId[$transaction->externalId] = $transaction;
        }

        self::assertCount(6, $byExternalId);
        self::assertSame(TransactionType::SALE, $byExternalId['ozon:operation:1234567890:sale']->type);
        self::assertSame(TransactionDirection::IN, $byExternalId['ozon:operation:1234567890:sale']->direction);
        self::assertSame(120050, $byExternalId['ozon:operation:1234567890:sale']->money->amountMinor());

        self::assertSame(TransactionType::COMMISSION, $byExternalId['ozon:operation:1234567890:commission']->type);
        self::assertSame(TransactionDirection::OUT, $byExternalId['ozon:operation:1234567890:commission']->direction);
        self::assertSame(12005, $byExternalId['ozon:operation:1234567890:commission']->money->amountMinor());

        self::assertSame(TransactionType::LOGISTICS, $byExternalId['ozon:operation:1234567890:logistics_delivery']->type);
        self::assertSame(TransactionType::LAST_MILE, $byExternalId['ozon:operation:1234567890:service_marketplaceserviceitemdelivtocustomer']->type);
        self::assertSame(TransactionType::FEE, $byExternalId['ozon:operation:1234567890:service_marketplaceserviceitemdropoffpvz']->type);
        self::assertSame(TransactionType::ACQUIRING, $byExternalId['ozon:operation:1234567890:acquiring']->type);
    }

    public function testRealizationFixtureUsesSameExternalIdsAndNewerVersion(): void
    {
        $dailyRows = $this->fixtureRows('transaction_list_with_sale_and_commission.json');
        $realizationRows = $this->fixtureRows('realization_february_2026.json');
        $daily = $this->dailyMapper()->map($this->rawRecord(OzonResourceType::DAILY_REPORT), $dailyRows);
        $realization = $this->realizationMapper()->map($this->rawRecord(OzonResourceType::REALIZATION), $realizationRows);

        self::assertSame(
            array_map(static fn ($transaction): string => $transaction->externalId, $daily),
            array_map(static fn ($transaction): string => $transaction->externalId, $realization),
        );

        $sale = $realization[0];
        self::assertSame('ozon:operation:1234567890:sale', $sale->externalId);
        self::assertSame(121000, $sale->money->amountMinor());
        self::assertEquals(new \DateTimeImmutable('2026-03-05T00:00:00+00:00'), $sale->externalUpdatedAt);
    }

    public function testRefundAndServicesFixtureIsDecomposed(): void
    {
        $rows = $this->fixtureRows('transaction_list_with_refund_and_services.json');
        $rawRecord = $this->rawRecord(OzonResourceType::DAILY_REPORT);
        $mapper = $this->dailyMapper();

        $transactions = $mapper->map($rawRecord, $rows);
        $controlSums = $mapper->controlSumForRawRecord($rawRecord, $rows);

        self::assertCount(5, $transactions);
        self::assertCount(1, $controlSums);
        self::assertSame(64794, $controlSums[0]->amountMinor);

        $byExternalId = [];
        foreach ($transactions as $transaction) {
            $byExternalId[$transaction->externalId] = $transaction;
        }

        self::assertSame(TransactionType::REFUND, $byExternalId['ozon:operation:refund-1001:refund']->type);
        self::assertSame(TransactionDirection::OUT, $byExternalId['ozon:operation:refund-1001:refund']->direction);
        self::assertSame(55040, $byExternalId['ozon:operation:refund-1001:refund']->money->amountMinor());

        self::assertSame(TransactionType::COMMISSION, $byExternalId['ozon:operation:refund-1001:commission']->type);
        self::assertSame(TransactionDirection::IN, $byExternalId['ozon:operation:refund-1001:commission']->direction);
        self::assertSame(5504, $byExternalId['ozon:operation:refund-1001:commission']->money->amountMinor());

        self::assertSame(TransactionType::LOGISTICS, $byExternalId['ozon:operation:refund-1001:logistics_return_delivery']->type);
        self::assertSame(TransactionType::LOGISTICS, $byExternalId['ozon:operation:refund-1001:service_marketplaceserviceitemreturnafterdelivtocustomer_0']->type);
        self::assertSame(TransactionType::FEE, $byExternalId['ozon:operation:refund-1001:service_premium_placement_1']->type);
    }

    public function testMissingOperationIdUsesStableFallbackKey(): void
    {
        $rows = $this->fixtureRows('transaction_list_without_operation_id.json');
        $rawRecord = $this->rawRecord(OzonResourceType::DAILY_REPORT);
        $transactions = $this->dailyMapper()->map($rawRecord, $rows);
        $controlSums = $this->dailyMapper()->controlSumForRawRecord($rawRecord, $rows);

        self::assertCount(1, $transactions);
        self::assertSame(
            'ozon:fallback:fallback-posting-1:fallback-sku-1:2026-02-20T11:00:00+00:00:other',
            $transactions[0]->externalId,
        );
        self::assertSame(TransactionType::OTHER, $transactions[0]->type);
        self::assertSame(TransactionDirection::IN, $transactions[0]->direction);
        self::assertSame(4210, $transactions[0]->money->amountMinor());
        self::assertSame($transactions[0]->operationGroupId, $controlSums[0]->operationGroupId);
        self::assertSame(4210, $controlSums[0]->amountMinor);
    }

    private function dailyMapper(): OzonSellerReportMapper
    {
        return new OzonSellerReportMapper($this->componentMapper());
    }

    private function realizationMapper(): OzonRealizationMapper
    {
        return new OzonRealizationMapper($this->componentMapper());
    }

    private function componentMapper(): OzonTransactionComponentMapper
    {
        return new OzonTransactionComponentMapper(new OzonMoneyParser(), new OzonOperationKey());
    }

    private function rawRecord(string $resourceType): IngestRawRecord
    {
        return new IngestRawRecord(
            companyId: '0192f0c2-0000-7000-8000-000000000001',
            connectionRef: 'marketplace:ozon:seller',
            shopRef: 'ozon-shop',
            source: IngestSource::OZON,
            resourceType: $resourceType,
            externalId: 'raw-'.$resourceType,
            storagePath: 'raw.ndjson.gz',
            hash: str_repeat('a', 64),
            byteSize: 100,
            fetchedAt: new \DateTimeImmutable('2026-03-05T00:00:00+00:00'),
            syncJobId: Uuid::uuid7()->toString(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureRows(string $fileName): array
    {
        $payload = json_decode(
            (string) file_get_contents(__DIR__.'/../../../../../Fixtures/Ingestion/Ozon/'.$fileName),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($payload);
        self::assertIsArray($payload['rows'] ?? null);

        /** @var list<array<string, mixed>> $rows */
        $rows = $payload['rows'];

        return $rows;
    }
}
