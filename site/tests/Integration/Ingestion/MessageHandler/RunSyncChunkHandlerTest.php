<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\MessageHandler;

use App\Ingestion\Application\Service\IngestRateLimitGuard;
use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Enum\SyncJobStatus;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Message\NormalizeRawRecordMessage;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Ingestion\MessageHandler\NormalizeRawRecordHandler;
use App\Ingestion\MessageHandler\RunSyncChunkHandler;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\IngestCursorRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Ingestion\Repository\SyncJobRepository;
use App\Tests\Integration\Ingestion\Fixtures\FakeConnector;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RunSyncChunkHandlerTest extends IntegrationTestCase
{
    public function testRunsFakeChunkStoresRawDispatchesNormalizationAndCompletesCanonFlow(): void
    {
        $this->fakeConnector()->reset();
        $companyId = Uuid::uuid7()->toString();
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: 'connection-1',
            source: IngestSource::WILDBERRIES,
            resourceType: FakeConnector::RESOURCE_TYPE,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-18'),
            windowTo: new \DateTimeImmutable('2026-06-18'),
            shopRef: 'shop-1',
        );

        $this->em->persist($job);
        $this->em->flush();

        $normalizeTransport = $this->getNormalizeTransport();
        $normalizeTransport->reset();

        /** @var RunSyncChunkHandler $handler */
        $handler = self::getContainer()->get(RunSyncChunkHandler::class);
        $handler(new RunSyncChunkMessage($companyId, $job->getId()));
        $this->em->clear();

        /** @var SyncJobRepository $jobRepository */
        $jobRepository = self::getContainer()->get(SyncJobRepository::class);
        $completedJob = $jobRepository->findByIdAndCompany($job->getId(), $companyId);
        self::assertNotNull($completedJob);
        self::assertSame(SyncJobStatus::COMPLETED, $completedJob->getStatus());

        /** @var IngestCursorRepository $cursorRepository */
        $cursorRepository = self::getContainer()->get(IngestCursorRepository::class);
        $cursor = $cursorRepository->findOne($companyId, 'connection-1', FakeConnector::RESOURCE_TYPE, 'shop-1');
        self::assertNull($cursor);

        $pullRequests = $this->fakeConnector()->pullRequests();
        self::assertCount(1, $pullRequests);
        self::assertNull($pullRequests[0]->cursorValue);

        $envelopes = $normalizeTransport->getSent();
        self::assertCount(1, $envelopes);
        self::assertInstanceOf(NormalizeRawRecordMessage::class, $envelopes[0]->getMessage());

        /** @var NormalizeRawRecordMessage $normalizeMessage */
        $normalizeMessage = $envelopes[0]->getMessage();
        /** @var IngestRawRecordRepository $rawRecordRepository */
        $rawRecordRepository = self::getContainer()->get(IngestRawRecordRepository::class);
        $rawRecord = $rawRecordRepository->findByIdAndCompany($normalizeMessage->rawRecordId, $companyId);

        self::assertNotNull($rawRecord);
        self::assertSame($job->getId(), $rawRecord->getSyncJobId());

        /** @var NormalizeRawRecordHandler $normalizeHandler */
        $normalizeHandler = self::getContainer()->get(NormalizeRawRecordHandler::class);
        $normalizeHandler($normalizeMessage);
        $this->em->clear();

        /** @var FinancialTransactionRepository $transactionRepository */
        $transactionRepository = self::getContainer()->get(FinancialTransactionRepository::class);
        $transactions = $transactionRepository->findByRawRecordId($companyId, $normalizeMessage->rawRecordId);

        self::assertCount(1, $transactions);
        self::assertSame('fake-sale-1', $transactions[0]->getExternalId());
        self::assertSame(12345, $transactions[0]->getAmountMinor());
    }

    public function testIncrementalChunkUsesAndAdvancesSharedCursor(): void
    {
        $this->fakeConnector()->reset();
        $companyId = Uuid::uuid7()->toString();
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: 'connection-1',
            source: IngestSource::WILDBERRIES,
            resourceType: FakeConnector::RESOURCE_TYPE,
            kind: SyncJobKind::INCREMENTAL,
            shopRef: 'shop-1',
        );

        $this->em->persist($job);
        $this->em->flush();

        /** @var IngestCursorRepository $cursorRepository */
        $cursorRepository = self::getContainer()->get(IngestCursorRepository::class);
        $cursor = $cursorRepository->getOrCreate($companyId, 'connection-1', FakeConnector::RESOURCE_TYPE, 'shop-1');
        $cursor->advance('cursor-before', $job->getId(), new \DateTimeImmutable('2026-06-18 09:00:00'));
        $this->em->flush();

        $this->getNormalizeTransport()->reset();

        /** @var RunSyncChunkHandler $handler */
        $handler = self::getContainer()->get(RunSyncChunkHandler::class);
        $handler(new RunSyncChunkMessage($companyId, $job->getId()));
        $this->em->clear();

        $pullRequests = $this->fakeConnector()->pullRequests();
        self::assertCount(1, $pullRequests);
        self::assertSame('cursor-before', $pullRequests[0]->cursorValue);

        $cursor = $cursorRepository->findOne($companyId, 'connection-1', FakeConnector::RESOURCE_TYPE, 'shop-1');
        self::assertNotNull($cursor);
        self::assertSame('cursor-after-fake-sale-1', $cursor->getCursorValue());

        /** @var SyncJobRepository $jobRepository */
        $jobRepository = self::getContainer()->get(SyncJobRepository::class);
        $completedJob = $jobRepository->findByIdAndCompany($job->getId(), $companyId);
        self::assertSame(SyncJobStatus::COMPLETED, $completedJob?->getStatus());
        self::assertSame('cursor-before', $completedJob?->getCursorSnapshot());
    }

    public function testWindowedChunkIgnoresExistingSharedCursor(): void
    {
        $this->fakeConnector()->reset();
        $companyId = Uuid::uuid7()->toString();
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: 'connection-1',
            source: IngestSource::WILDBERRIES,
            resourceType: FakeConnector::RESOURCE_TYPE,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-08'),
            windowTo: new \DateTimeImmutable('2026-06-14'),
            shopRef: 'shop-1',
        );

        $this->em->persist($job);
        $this->em->flush();

        /** @var IngestCursorRepository $cursorRepository */
        $cursorRepository = self::getContainer()->get(IngestCursorRepository::class);
        $cursor = $cursorRepository->getOrCreate($companyId, 'connection-1', FakeConnector::RESOURCE_TYPE, 'shop-1');
        $cursor->advance('2099-01-01', $job->getId(), new \DateTimeImmutable('2026-06-18 09:00:00'));
        $this->em->flush();

        $this->getNormalizeTransport()->reset();

        /** @var RunSyncChunkHandler $handler */
        $handler = self::getContainer()->get(RunSyncChunkHandler::class);
        $handler(new RunSyncChunkMessage($companyId, $job->getId()));
        $this->em->clear();

        $pullRequests = $this->fakeConnector()->pullRequests();
        self::assertCount(1, $pullRequests);
        self::assertNull($pullRequests[0]->cursorValue);
        self::assertEquals(new \DateTimeImmutable('2026-06-08'), $pullRequests[0]->windowFrom);
        self::assertEquals(new \DateTimeImmutable('2026-06-14'), $pullRequests[0]->windowTo);

        $cursor = $cursorRepository->findOne($companyId, 'connection-1', FakeConnector::RESOURCE_TYPE, 'shop-1');
        self::assertNotNull($cursor);
        self::assertSame('2099-01-01', $cursor->getCursorValue());
    }

    public function testWindowedChunkContinuesPullingUntilConnectorHasNoMoreData(): void
    {
        $fakeConnector = $this->fakeConnector();
        $fakeConnector->reset();
        $fakeConnector->enqueuePullResult('fake-report-page-1', '2026-06-08', true, 'fake-sale-1');
        $fakeConnector->enqueuePullResult('fake-report-page-2', null, false, 'fake-sale-2');

        $companyId = Uuid::uuid7()->toString();
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: 'connection-1',
            source: IngestSource::WILDBERRIES,
            resourceType: FakeConnector::RESOURCE_TYPE,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-14'),
            shopRef: 'shop-1',
        );

        $this->em->persist($job);
        $this->em->flush();

        $normalizeTransport = $this->getNormalizeTransport();
        $normalizeTransport->reset();

        /** @var RunSyncChunkHandler $handler */
        $handler = self::getContainer()->get(RunSyncChunkHandler::class);
        $handler(new RunSyncChunkMessage($companyId, $job->getId()));
        $this->em->clear();

        $pullRequests = $fakeConnector->pullRequests();
        self::assertCount(2, $pullRequests);
        self::assertNull($pullRequests[0]->cursorValue);
        self::assertSame('2026-06-08', $pullRequests[1]->cursorValue);
        self::assertCount(2, $normalizeTransport->getSent());

        /** @var SyncJobRepository $jobRepository */
        $jobRepository = self::getContainer()->get(SyncJobRepository::class);
        self::assertSame(SyncJobStatus::COMPLETED, $jobRepository->findByIdAndCompany($job->getId(), $companyId)?->getStatus());

        /** @var IngestCursorRepository $cursorRepository */
        $cursorRepository = self::getContainer()->get(IngestCursorRepository::class);
        self::assertNull($cursorRepository->findOne($companyId, 'connection-1', FakeConnector::RESOURCE_TYPE, 'shop-1'));
    }

    public function testWindowedChunkSchedulesDelayedContinuationWhenConnectorRequestsDelay(): void
    {
        $fakeConnector = $this->fakeConnector();
        $fakeConnector->reset();
        $fakeConnector->enqueuePullResult(
            externalId: 'fake-report-page-1',
            nextCursorValue: 'cursor-page-2',
            hasMore: true,
            rowExternalId: 'fake-sale-1',
            normalizeRawRecords: false,
            continuationDelaySeconds: 70,
        );

        $companyId = Uuid::uuid7()->toString();
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: 'connection-1',
            source: IngestSource::WILDBERRIES,
            resourceType: FakeConnector::RESOURCE_TYPE,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-01'),
            shopRef: 'shop-1',
        );

        $this->em->persist($job);
        $this->em->flush();

        $fetchTransport = $this->getFetchTransport();
        $fetchTransport->reset();
        $normalizeTransport = $this->getNormalizeTransport();
        $normalizeTransport->reset();

        /** @var RunSyncChunkHandler $handler */
        $handler = self::getContainer()->get(RunSyncChunkHandler::class);
        $handler(new RunSyncChunkMessage($companyId, $job->getId()));
        $this->em->clear();

        self::assertCount(1, $fakeConnector->pullRequests());
        self::assertCount(0, $normalizeTransport->getSent());

        $sent = $fetchTransport->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(RunSyncChunkMessage::class, $sent[0]->getMessage());

        /** @var RunSyncChunkMessage $continuation */
        $continuation = $sent[0]->getMessage();
        self::assertSame($companyId, $continuation->companyId);
        self::assertSame($job->getId(), $continuation->jobId);
        self::assertSame('cursor-page-2', $continuation->cursorValue);

        $delayStamps = $sent[0]->all(DelayStamp::class);
        self::assertCount(1, $delayStamps);
        self::assertSame(70000, $delayStamps[0]->getDelay());

        /** @var SyncJobRepository $jobRepository */
        $jobRepository = self::getContainer()->get(SyncJobRepository::class);
        self::assertSame(SyncJobStatus::RUNNING, $jobRepository->findByIdAndCompany($job->getId(), $companyId)?->getStatus());

        /** @var IngestRawRecordRepository $rawRecordRepository */
        $rawRecordRepository = self::getContainer()->get(IngestRawRecordRepository::class);
        $rawRecords = $rawRecordRepository->findBy(['companyId' => $companyId, 'syncJobId' => $job->getId()]);
        self::assertCount(1, $rawRecords);
        self::assertSame(RawNormalizationStatus::SKIPPED, $rawRecords[0]->getNormalizationStatus());
    }

    public function testRateLimitLockContentionKeepsJobRetryable(): void
    {
        $this->fakeConnector()->reset();
        $companyId = Uuid::uuid7()->toString();
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: 'connection-1',
            source: IngestSource::WILDBERRIES,
            resourceType: FakeConnector::RESOURCE_TYPE,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-18'),
            windowTo: new \DateTimeImmutable('2026-06-18'),
            shopRef: 'shop-1',
        );

        $this->em->persist($job);
        $this->em->flush();

        /** @var IngestRateLimitGuard $rateLimitGuard */
        $rateLimitGuard = self::getContainer()->get(IngestRateLimitGuard::class);
        $heldLock = $rateLimitGuard->acquire('wildberries:connection-1');

        try {
            /** @var RunSyncChunkHandler $handler */
            $handler = self::getContainer()->get(RunSyncChunkHandler::class);
            $handler(new RunSyncChunkMessage($companyId, $job->getId()));

            self::fail('Expected rate-limit lock contention to be retryable.');
        } catch (ConnectorTransientException) {
        } finally {
            $heldLock->release();
        }

        $this->em->clear();

        /** @var SyncJobRepository $jobRepository */
        $jobRepository = self::getContainer()->get(SyncJobRepository::class);
        $retryableJob = $jobRepository->findByIdAndCompany($job->getId(), $companyId);

        self::assertNotNull($retryableJob);
        self::assertSame(SyncJobStatus::RUNNING, $retryableJob->getStatus());
        self::assertNull($retryableJob->getLastError());
    }

    public function testTransientThrowableKeepsJobRetryableThenCompletesOnRetry(): void
    {
        $fakeConnector = $this->fakeConnector();
        $fakeConnector->reset();
        $fakeConnector->failNextPullWith(new \RuntimeException('Transient database deadlock.'));

        $companyId = Uuid::uuid7()->toString();
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: 'connection-1',
            source: IngestSource::WILDBERRIES,
            resourceType: FakeConnector::RESOURCE_TYPE,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-18'),
            windowTo: new \DateTimeImmutable('2026-06-18'),
            shopRef: 'shop-1',
        );

        $this->em->persist($job);
        $this->em->flush();

        $this->getNormalizeTransport()->reset();

        /** @var RunSyncChunkHandler $handler */
        $handler = self::getContainer()->get(RunSyncChunkHandler::class);

        /** @var SyncJobRepository $jobRepository */
        $jobRepository = self::getContainer()->get(SyncJobRepository::class);

        // First attempt: a generic transient failure must NOT mark the job FAILED; it
        // is rethrown so the Messenger retry strategy can apply.
        try {
            $handler(new RunSyncChunkMessage($companyId, $job->getId()));
            self::fail('Expected the transient failure to be rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Transient database deadlock.', $exception->getMessage());
        }
        $this->em->clear();

        $retryableJob = $jobRepository->findByIdAndCompany($job->getId(), $companyId);
        self::assertNotNull($retryableJob);
        self::assertSame(SyncJobStatus::RUNNING, $retryableJob->getStatus());
        self::assertNull($retryableJob->getLastError());

        // Retry attempt: the connector now succeeds, so the job completes.
        $handler(new RunSyncChunkMessage($companyId, $job->getId()));
        $this->em->clear();

        $completedJob = $jobRepository->findByIdAndCompany($job->getId(), $companyId);
        self::assertNotNull($completedJob);
        self::assertSame(SyncJobStatus::COMPLETED, $completedJob->getStatus());
    }

    private function fakeConnector(): FakeConnector
    {
        /** @var FakeConnector $connector */
        $connector = self::getContainer()->get(FakeConnector::class);

        return $connector;
    }

    private function getNormalizeTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.ingest_normalize');

        return $transport;
    }

    private function getFetchTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.ingest_fetch');

        return $transport;
    }
}
