<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\NormalizeRawRecordAction;
use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Facade\IngestionFacade;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Ingestion\Repository\NormalizationIssueRepository;
use App\Tests\Integration\Ingestion\Fixtures\FakeConnector;
use App\Tests\Integration\Ingestion\Fixtures\NormalizationCompletedRecorder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class NormalizeRawRecordActionTest extends IntegrationTestCase
{
    public function testNormalizesRawRecordUpsertsTransactionAndDispatchesEvent(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $operationGroupId = Uuid::uuid7()->toString();
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

        $recorder = $this->eventRecorder();
        $recorder->reset();

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
        self::assertNotNull($transactions[0]->getCounterpartyId());

        /** @var IngestionFacade $facade */
        $facade = self::getContainer()->get(IngestionFacade::class);
        self::assertSame(0, $facade->countOpenIssues($companyId));
        self::assertSame(
            [$transactions[0]->getId()],
            array_map(
                static fn (FinancialTransaction $transaction): string => $transaction->getId(),
                iterator_to_array($facade->getTransactions(
                    $companyId,
                    new \DateTimeImmutable('2026-06-18 00:00:00'),
                    new \DateTimeImmutable('2026-06-18 23:59:59'),
                    'shop-1',
                )),
            ),
        );

        $events = $recorder->events();
        self::assertCount(1, $events);
        self::assertSame($companyId, $events[0]->companyId);
        self::assertSame($record->getId(), $events[0]->rawRecordId);
        self::assertCount(1, $events[0]->affectedPeriods);
        self::assertNull($events[0]->affectedPeriods[0]->oldOccurredAt);
        self::assertEquals(new \DateTimeImmutable('2026-06-18T09:00:00+00:00'), $events[0]->affectedPeriods[0]->newOccurredAt);
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

        $this->eventRecorder()->reset();
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

    public function testMapperFailureRecordsIssueAndMarksRawRecordFailed(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord($companyId, [[
            'failMapper' => true,
        ]]);

        $recorder = $this->eventRecorder();
        $recorder->reset();

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
        self::assertSame([], $recorder->events());
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

    private function eventRecorder(): NormalizationCompletedRecorder
    {
        /** @var NormalizationCompletedRecorder $recorder */
        $recorder = self::getContainer()->get(NormalizationCompletedRecorder::class);

        return $recorder;
    }
}
