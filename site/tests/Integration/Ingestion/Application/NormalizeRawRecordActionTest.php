<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\NormalizeRawRecordAction;
use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Application\DTO\FinancialTransactionView;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Facade\IngestionFacade;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Ingestion\Repository\NormalizationIssueRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Integration\Ingestion\Fixtures\FakeConnector;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

final class NormalizeRawRecordActionTest extends IntegrationTestCase
{
    public function testNormalizesRawRecordAndUpsertsTransaction(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $operationGroupId = Uuid::uuid7()->toString();
        $systemCounterpartyId = $this->ensureWildberriesSystemCounterparty();

        $record = $this->storeRawRecord($companyId, [[
            'externalId' => 'sale-1',
            'externalUpdatedAt' => '2026-06-18T10:00:00+00:00',
            'operationGroupId' => $operationGroupId,
            'amountMinor' => 10000,
            'controlAmountMinor' => 10000,
            'currency' => 'RUB',
            'occurredAt' => '2026-06-18T09:00:00+00:00',
            'counterpartyExternalKey' => 'buyer-1',
            'counterpartyName' => 'Buyer One',
        ]]);

        $this->normalize($record->getId(), $companyId);
        $this->em->clear();

        /** @var IngestRawRecordRepository $rawRecordRepository */
        $rawRecordRepository = self::getContainer()->get(IngestRawRecordRepository::class);
        $rawRecord = $rawRecordRepository->findByIdAndCompany($record->getId(), $companyId);

        self::assertNotNull($rawRecord);
        self::assertSame(RawNormalizationStatus::DONE, $rawRecord->getNormalizationStatus());

        /** @var FinancialTransactionRepository $transactionRepository */
        $transactionRepository = self::getContainer()->get(FinancialTransactionRepository::class);
        $transactions = $transactionRepository->findByRawRecordId($companyId, $record->getId());

        self::assertCount(1, $transactions);
        self::assertSame('sale-1', $transactions[0]->getExternalId());
        self::assertSame(10000, $transactions[0]->getAmountMinor());
        self::assertSame('RUB', $transactions[0]->getCurrency());
        self::assertSame($systemCounterpartyId, $transactions[0]->getCounterpartyId());

        /** @var IngestionFacade $facade */
        $facade = self::getContainer()->get(IngestionFacade::class);
        self::assertSame(0, $facade->countOpenIssues($companyId));
        self::assertSame(
            [$transactions[0]->getId()],
            array_map(
                static fn (FinancialTransactionView $transaction): string => $transaction->id,
                iterator_to_array($facade->getTransactions(
                    $companyId,
                    new \DateTimeImmutable('2026-06-18 00:00:00'),
                    new \DateTimeImmutable('2026-06-18 23:59:59'),
                    'shop-1',
                )),
            ),
        );

        foreach (['pl_daily_totals', 'pl_monthly_snapshots'] as $tableName) {
            self::assertSame(
                0,
                (int) $this->connection->fetchOne(
                    sprintf('SELECT COUNT(*) FROM %s WHERE company_id = :companyId', $tableName),
                    ['companyId' => $companyId],
                ),
                sprintf('Normalization must not project Ingestion data into %s.', $tableName),
            );
        }
    }

    public function testReNormalizationWithUnchangedContentDoesNotCreateDuplicate(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $row = [
            'externalId' => 'sale-1',
            'externalUpdatedAt' => '2026-06-18T10:00:00+00:00',
            'operationGroupId' => Uuid::uuid7()->toString(),
            'amountMinor' => 10000,
            'controlAmountMinor' => 10000,
            'currency' => 'RUB',
            'occurredAt' => '2026-06-18T09:00:00+00:00',
        ];

        $first = $this->storeRawRecord($companyId, [$row]);
        $this->normalize($first->getId(), $companyId);
        $this->em->clear();

        // A second raw record with byte-identical content normalizes to the same
        // natural key; every upsert is a no-change (B3).
        $second = $this->storeRawRecord($companyId, [$row]);
        $this->normalize($second->getId(), $companyId);
        $this->em->clear();

        /** @var FinancialTransactionRepository $transactionRepository */
        $transactionRepository = self::getContainer()->get(FinancialTransactionRepository::class);
        // No duplicate is created; raw attribution moves to the latest identical snapshot.
        self::assertCount(0, $transactionRepository->findByRawRecordId($companyId, $first->getId()));
        self::assertCount(1, $transactionRepository->findByRawRecordId($companyId, $second->getId()));
    }

    public function testControlSumMismatchRecordsIssueButMarksRawRecordDone(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $operationGroupId = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord($companyId, [[
            'externalId' => 'sale-1',
            'operationGroupId' => $operationGroupId,
            'amountMinor' => 10000,
            'controlAmountMinor' => 9000,
            'currency' => 'RUB',
        ]]);

        $this->normalize($record->getId(), $companyId);
        $this->em->clear();

        /** @var NormalizationIssueRepository $issueRepository */
        $issueRepository = self::getContainer()->get(NormalizationIssueRepository::class);
        $issues = $issueRepository->findOpenByRawRecord($companyId, $record->getId());

        self::assertCount(1, $issues);
        self::assertSame(NormalizationIssueKind::SUM_MISMATCH, $issues[0]->getKind());
        self::assertSame($operationGroupId, $issues[0]->getOperationGroupId());
        self::assertSame(9000, $issues[0]->getDetails()['expectedAmountMinor']);
        self::assertSame(10000, $issues[0]->getDetails()['actualAmountMinor']);

        /** @var IngestRawRecordRepository $rawRecordRepository */
        $rawRecordRepository = self::getContainer()->get(IngestRawRecordRepository::class);
        self::assertSame(
            RawNormalizationStatus::DONE,
            $rawRecordRepository->findByIdAndCompany($record->getId(), $companyId)?->getNormalizationStatus(),
        );
    }

    public function testSuccessfulNormalizationResolvesPreviousOpenIssues(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord($companyId, [[
            'externalId' => 'sale-with-old-issue',
            'externalUpdatedAt' => '2026-06-18T10:00:00+00:00',
            'operationGroupId' => Uuid::uuid7()->toString(),
            'amountMinor' => 10000,
            'controlAmountMinor' => 10000,
            'currency' => 'RUB',
            'occurredAt' => '2026-06-18T09:00:00+00:00',
        ]]);

        $this->em->persist(new \App\Ingestion\Entity\NormalizationIssue(
            $companyId,
            $record->getId(),
            null,
            NormalizationIssueKind::MAPPER_FAILURE,
            ['message' => 'old failure'],
        ));
        $this->em->flush();

        $this->normalize($record->getId(), $companyId);
        $this->em->clear();

        /** @var NormalizationIssueRepository $issueRepository */
        $issueRepository = self::getContainer()->get(NormalizationIssueRepository::class);
        self::assertSame([], $issueRepository->findOpenByRawRecord($companyId, $record->getId()));
    }

    public function testMapperFailureRecordsIssueAndMarksRawRecordFailed(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord($companyId, [[
            'failMapper' => true,
        ]]);

        $this->normalize($record->getId(), $companyId);
        $this->em->clear();

        /** @var NormalizationIssueRepository $issueRepository */
        $issueRepository = self::getContainer()->get(NormalizationIssueRepository::class);
        $issues = $issueRepository->findOpenByRawRecord($companyId, $record->getId());

        self::assertCount(1, $issues);
        self::assertSame(NormalizationIssueKind::MAPPER_FAILURE, $issues[0]->getKind());

        /** @var FinancialTransactionRepository $transactionRepository */
        $transactionRepository = self::getContainer()->get(FinancialTransactionRepository::class);
        self::assertSame([], $transactionRepository->findByRawRecordId($companyId, $record->getId()));

        /** @var IngestRawRecordRepository $rawRecordRepository */
        $rawRecordRepository = self::getContainer()->get(IngestRawRecordRepository::class);
        self::assertSame(
            RawNormalizationStatus::FAILED,
            $rawRecordRepository->findByIdAndCompany($record->getId(), $companyId)?->getNormalizationStatus(),
        );
    }

    public function testConcurrentInsertViolationIsTranslatedToRecoverableRetry(): void
    {
        $companyId = Uuid::uuid7()->toString();

        $record = $this->storeRawRecord($companyId, [[
            'externalId' => 'sale-1',
            'operationGroupId' => Uuid::uuid7()->toString(),
            'amountMinor' => 10000,
            'controlAmountMinor' => 10000,
            'currency' => 'RUB',
        ]]);

        // Reproduce the lost-race window: a colliding natural key is pending but NOT
        // flushed, so findByNaturalKey's DB query misses it. It is inserted in the same
        // batch flush as the upsert's row, raising a unique violation at flush time —
        // exactly what a concurrent worker that committed first would cause.
        $this->em->persist($this->financialTransaction($companyId, 'sale-1', 20000));

        /** @var NormalizeRawRecordAction $action */
        $action = self::getContainer()->get(NormalizeRawRecordAction::class);

        try {
            $action(new NormalizeRawRecordCommand($record->getId(), $companyId));
            self::fail('Expected a RecoverableMessageHandlingException.');
        } catch (RecoverableMessageHandlingException $exception) {
            self::assertInstanceOf(UniqueConstraintViolationException::class, $exception->getPrevious());
        }

        // The transaction was rolled back, so no row leaked for this company.
        self::assertSame(
            0,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ingest_financial_transactions WHERE company_id = ?',
                [$companyId],
            ),
        );
    }

    private function financialTransaction(string $companyId, string $externalId, int $amount): FinancialTransaction
    {
        return new FinancialTransaction(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::WILDBERRIES,
            externalId: $externalId,
            externalUpdatedAt: new \DateTimeImmutable('2026-06-18 10:00:00'),
            operationGroupId: Uuid::uuid7()->toString(),
            type: TransactionType::SALE,
            direction: TransactionDirection::IN,
            money: Money::fromMinor($amount, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-06-18 09:00:00'),
            rawRecordId: Uuid::uuid7()->toString(),
            orderRef: null,
            payoutRef: null,
            counterpartyId: null,
            description: null,
            sourceData: ['amountMinor' => $amount],
            sourceTz: 'UTC',
        );
    }

    private function ensureWildberriesSystemCounterparty(): string
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO system_counterparties (id, source, name, created_at)
                VALUES (:id, :source, :name, :createdAt)
                ON CONFLICT (source) DO NOTHING
                SQL,
            [
                'id' => '95d09265-b44f-5b95-a12c-f1e3332c657d',
                'source' => IngestSource::WILDBERRIES->value,
                'name' => 'Wildberries',
                'createdAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            ],
        );

        return (string) $this->connection->fetchOne(
            'SELECT id FROM system_counterparties WHERE source = :source',
            ['source' => IngestSource::WILDBERRIES->value],
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function storeRawRecord(string $companyId, array $rows): IngestRawRecord
    {
        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);

        return $facade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::WILDBERRIES,
            resourceType: FakeConnector::RESOURCE_TYPE,
            externalId: 'raw-'.Uuid::uuid7()->toString(),
            syncJobId: 'sync-job-1',
            fetchedAt: new \DateTimeImmutable('2026-06-18 10:00:00'),
            rows: $rows,
        ))[0];
    }

    private function normalize(string $rawRecordId, string $companyId): void
    {
        /** @var NormalizeRawRecordAction $action */
        $action = self::getContainer()->get(NormalizeRawRecordAction::class);
        $action(new NormalizeRawRecordCommand($rawRecordId, $companyId));
    }
}
