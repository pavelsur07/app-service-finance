<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\MessageHandler;

use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Enum\SyncJobStatus;
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
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RunSyncChunkHandlerTest extends IntegrationTestCase
{
    public function testRunsFakeChunkStoresRawDispatchesNormalizationAndCompletesCanonFlow(): void
    {
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
        self::assertNotNull($cursor);
        self::assertSame('cursor-after-fake-sale-1', $cursor->getCursorValue());

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

    private function getNormalizeTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.ingest_normalize');

        return $transport;
    }
}
