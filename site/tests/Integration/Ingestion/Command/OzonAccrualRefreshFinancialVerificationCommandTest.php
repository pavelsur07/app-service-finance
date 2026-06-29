<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonAccrualRefreshFinancialVerificationCommandTest extends IntegrationTestCase
{
    public function testExecuteDoesNotDoubleCountUnresolvedEnrichmentRowsAcrossBatches(): void
    {
        $owner = UserBuilder::aUser()->withIndex(9510)->build();
        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-4111-8111-111111119510')
            ->withOwner($owner)
            ->build();
        $companyId = (string) $company->getId();
        $connectionRef = Uuid::uuid7()->toString();
        $rawRecordId = Uuid::uuid7()->toString();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->persistExistingTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            rawRecordId: $rawRecordId,
            operationGroupId: Uuid::uuid7()->toString(),
            externalId: 'ozon:accrual-by-day:95100000001:non_item_fee:type-12',
            type: TransactionType::OTHER,
            direction: TransactionDirection::OUT,
            amountMinor: -50,
            occurredAt: new \DateTimeImmutable('2026-06-01 00:00:00+03:00'),
        );
        $this->persistExistingTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            rawRecordId: $rawRecordId,
            operationGroupId: Uuid::uuid7()->toString(),
            externalId: 'ozon:accrual-by-day:95100000002:bonus:product-0',
            type: TransactionType::BONUS,
            direction: TransactionDirection::IN,
            amountMinor: 100,
            sourceData: ['sku' => 'metric-sku-1', 'name' => 'Metric SKU 1'],
            occurredAt: new \DateTimeImmutable('2026-06-01 00:01:00+03:00'),
        );
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--shop-ref' => $connectionRef,
            '--from' => '2026-06-01',
            '--to' => '2026-06-07',
            '--raw-limit' => 10,
            '--relink-limit' => 1,
            '--max-relink-batches' => 2,
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame(1, $this->linkedTransactionCount($companyId));
        self::assertMatchesRegularExpression('/Listing enrichment repair[\s\S]*selected\s+2[\s\S]*updated\s+1[\s\S]*unresolved\s+1/', $tester->getDisplay());
    }

    public function testExecuteProcessesPendingRawRecordByDefault(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-06-01:2026-06-07',
            fetchedAt: new \DateTimeImmutable('2026-06-08 03:00:00+00:00'),
            rows: [$this->postingRow()],
        );

        $tester = $this->tester();
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--shop-ref' => $connectionRef,
            '--from' => '2026-06-01',
            '--to' => '2026-06-07',
            '--raw-limit' => 10,
            '--relink-limit' => 10,
            '--max-relink-batches' => 1,
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame(RawNormalizationStatus::DONE, $this->rawStatusById($companyId, $record->getId()));
        self::assertSame(3, $this->transactionCount($companyId, $record->getId()));
    }

    public function testExecuteReplaysDoneRawRecordForVerificationReports(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-06-01:2026-06-07',
            fetchedAt: new \DateTimeImmutable('2026-06-08 03:00:00+00:00'),
            rows: [$this->postingRow()],
        );
        $operationGroupId = Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('%s:ozon:accrual-by-day:%s', $companyId, '53675409100'))->toString();
        $this->persistExistingTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            rawRecordId: $record->getId(),
            operationGroupId: $operationGroupId,
            externalId: 'ozon:accrual-by-day:53675409100:sale:product-0',
            type: TransactionType::SALE,
            direction: TransactionDirection::IN,
            amountMinor: 10000,
        );
        $this->persistExistingTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            rawRecordId: $record->getId(),
            operationGroupId: $operationGroupId,
            externalId: 'ozon:accrual-by-day:53675409100:commission:product-0',
            type: TransactionType::COMMISSION,
            direction: TransactionDirection::OUT,
            amountMinor: 3000,
        );
        $record->markNormalizationDone();
        $this->em->flush();

        $defaultTester = $this->tester();
        $defaultExit = $defaultTester->execute([
            '--company-id' => $companyId,
            '--shop-ref' => $connectionRef,
            '--from' => '2026-06-01',
            '--to' => '2026-06-07',
            '--raw-limit' => 10,
            '--relink-limit' => 10,
            '--max-relink-batches' => 1,
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $defaultExit, $defaultTester->getDisplay());
        self::assertSame(2, $this->transactionCount($companyId, $record->getId()));

        $tester = $this->tester();
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--shop-ref' => $connectionRef,
            '--from' => '2026-06-01',
            '--to' => '2026-06-07',
            '--raw-limit' => 10,
            '--relink-limit' => 10,
            '--max-relink-batches' => 1,
            '--include-done' => true,
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame(RawNormalizationStatus::DONE, $this->rawStatusById($companyId, $record->getId()));
        self::assertSame(3, $this->transactionCount($companyId, $record->getId()));
        self::assertSame(1, $this->transactionCountByType($companyId, $record->getId(), TransactionType::BONUS));
        self::assertSame(0, $this->duplicateNaturalKeyCount($companyId));
        self::assertStringContainsString('Ozon accrual financial verification refresh', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find('app:ingestion:ozon-accrual:refresh-financial-verification'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function storeRawRecord(
        string $companyId,
        string $connectionRef,
        string $externalId,
        \DateTimeImmutable $fetchedAt,
        array $rows,
    ): IngestRawRecord {
        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);

        return $facade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            externalId: $externalId,
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: $fetchedAt,
            rows: $rows,
        ))[0];
    }

    private function persistExistingTransaction(
        string $companyId,
        string $connectionRef,
        string $rawRecordId,
        string $operationGroupId,
        string $externalId,
        TransactionType $type,
        TransactionDirection $direction,
        int $amountMinor,
        array $sourceData = [],
        ?\DateTimeImmutable $occurredAt = null,
    ): void {
        $this->em->persist(new FinancialTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::OZON,
            externalId: $externalId,
            externalUpdatedAt: new \DateTimeImmutable('2026-06-08 03:00:00+00:00'),
            operationGroupId: $operationGroupId,
            type: $type,
            direction: $direction,
            money: Money::fromMinor($amountMinor, 'RUB'),
            occurredAt: $occurredAt ?? new \DateTimeImmutable('2026-06-01 00:00:00+03:00'),
            rawRecordId: $rawRecordId,
            description: 'Existing Ozon accrual transaction',
            sourceData: $sourceData,
            sourceTz: 'Europe/Moscow',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function postingRow(): array
    {
        return [
            'accrual_id' => 53675409100,
            'date' => '2026-06-01',
            'unit_number' => '41774559-0885-1',
            'accrued_category' => 'POSTING',
            'posting' => [
                'products' => [[
                    'commission' => [
                        'sale_amount' => ['amount' => '100.00', 'currency' => 'RUB'],
                        'bonus' => ['amount' => '20.00', 'currency' => 'RUB'],
                        'commission' => ['amount' => '-30.00', 'currency' => 'RUB'],
                    ],
                ]],
            ],
        ];
    }

    private function rawStatusById(string $companyId, string $rawRecordId): RawNormalizationStatus
    {
        /** @var IngestRawRecordRepository $repository */
        $repository = self::getContainer()->get(IngestRawRecordRepository::class);

        return $repository->findByIdAndCompany($rawRecordId, $companyId)?->getNormalizationStatus()
            ?? throw new \RuntimeException('Raw record was not found.');
    }

    private function transactionCount(string $companyId, string $rawRecordId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_financial_transactions WHERE company_id = :companyId AND raw_record_id = :rawRecordId',
            ['companyId' => $companyId, 'rawRecordId' => $rawRecordId],
        );
    }

    private function transactionCountByType(string $companyId, string $rawRecordId, TransactionType $type): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_financial_transactions WHERE company_id = :companyId AND raw_record_id = :rawRecordId AND type = :type',
            ['companyId' => $companyId, 'rawRecordId' => $rawRecordId, 'type' => $type->value],
        );
    }

    private function linkedTransactionCount(string $companyId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_financial_transactions WHERE company_id = :companyId AND listing_id IS NOT NULL',
            ['companyId' => $companyId],
        );
    }

    private function duplicateNaturalKeyCount(string $companyId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM (
                 SELECT external_id, type
                 FROM ingest_financial_transactions
                 WHERE company_id = :companyId
                   AND source = :source
                 GROUP BY external_id, type
                 HAVING COUNT(*) > 1
             ) duplicate_keys',
            ['companyId' => $companyId, 'source' => IngestSource::OZON->value],
        );
    }
}
