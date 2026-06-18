<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Command\MarkJobCompletedCommand;
use App\Ingestion\Application\Command\MarkJobRunningCommand;
use App\Ingestion\Application\Command\StartBackfillCommand;
use App\Ingestion\Application\Command\UpdateCursorCommand;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\SyncJobStatus;
use App\Ingestion\Facade\SyncFacade;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Ingestion\Repository\IngestCursorRepository;
use App\Ingestion\Repository\SyncJobRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SyncFacadeTest extends IntegrationTestCase
{
    public function testStartBackfillCreatesParentChunksAndDispatchesChunkMessages(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        /** @var SyncFacade $facade */
        $facade = self::getContainer()->get(SyncFacade::class);

        $parentJobId = $facade->startBackfill(new StartBackfillCommand(
            companyId: $companyId,
            connectionRef: 'connection-1',
            source: IngestSource::OZON,
            resourceType: 'resource-1',
            shopRef: 'shop-1',
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-30'),
        ));

        /** @var SyncJobRepository $jobRepository */
        $jobRepository = self::getContainer()->get(SyncJobRepository::class);
        $parent = $jobRepository->findByIdAndCompany($parentJobId, $companyId);

        self::assertNotNull($parent);
        self::assertSame(SyncJobStatus::RUNNING, $parent->getStatus());
        self::assertSame(5, $parent->getProgressTotal());
        self::assertSame(0, $parent->getProgressDone());

        $envelopes = $transport->getSent();
        self::assertCount(5, $envelopes);
        foreach ($envelopes as $envelope) {
            self::assertInstanceOf(RunSyncChunkMessage::class, $envelope->getMessage());
            self::assertSame($companyId, $envelope->getMessage()->getCompanyId());
        }
    }

    public function testCompletingAllChildrenFinalizesParent(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $transport = $this->getIngestFetchTransport();
        $transport->reset();

        /** @var SyncFacade $facade */
        $facade = self::getContainer()->get(SyncFacade::class);
        $parentJobId = $facade->startBackfill(new StartBackfillCommand(
            companyId: $companyId,
            connectionRef: 'connection-1',
            source: IngestSource::OZON,
            resourceType: 'resource-1',
            shopRef: 'shop-1',
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-14'),
        ));

        foreach ($transport->getSent() as $envelope) {
            /** @var RunSyncChunkMessage $message */
            $message = $envelope->getMessage();
            $facade->markJobRunning(new MarkJobRunningCommand($message->jobId, $companyId));
            $facade->markJobCompleted(new MarkJobCompletedCommand($message->jobId, $companyId));
        }

        $progress = $facade->getProgress($parentJobId, $companyId);

        self::assertSame(SyncJobStatus::COMPLETED, $progress->status);
        self::assertSame(2, $progress->progressTotal);
        self::assertSame(2, $progress->progressDone);
    }

    public function testUpdateCursorCreatesAndAdvancesCursor(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $syncJobId = Uuid::uuid7()->toString();

        /** @var SyncFacade $facade */
        $facade = self::getContainer()->get(SyncFacade::class);

        $facade->updateCursor(new UpdateCursorCommand(
            companyId: $companyId,
            connectionRef: 'connection-1',
            resourceType: 'resource-1',
            shopRef: 'shop-1',
            newCursorValue: 'cursor-2',
            syncJobId: $syncJobId,
            fetchedAt: new \DateTimeImmutable('2026-06-18 10:00:00'),
        ));

        /** @var IngestCursorRepository $cursorRepository */
        $cursorRepository = self::getContainer()->get(IngestCursorRepository::class);
        $cursor = $cursorRepository->findOne($companyId, 'connection-1', 'resource-1', 'shop-1');

        self::assertNotNull($cursor);
        self::assertSame('cursor-2', $cursor->getCursorValue());
        self::assertSame($syncJobId, $cursor->getLastSyncJobId());
    }

    private function getIngestFetchTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.ingest_fetch');

        return $transport;
    }
}
