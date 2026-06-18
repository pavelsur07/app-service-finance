<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Infrastructure\Messenger;

use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Enum\SyncJobStatus;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Infrastructure\Messenger\SyncJobFailureSubscriber;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Ingestion\Repository\SyncJobRepository;
use App\Tests\Integration\Ingestion\Fixtures\FakeConnector;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class SyncJobFailureSubscriberTest extends IntegrationTestCase
{
    public function testWillRetryKeepsRunningJobNonTerminal(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $job = $this->newBackfillJob($companyId);
        $job->markRunning();

        $this->em->persist($job);
        $this->em->flush();

        $event = new WorkerMessageFailedEvent(
            new Envelope(new RunSyncChunkMessage($companyId, $job->getId())),
            'ingest_fetch',
            new ConnectorTransientException('temporary'),
        );
        $event->setForRetry();

        /** @var SyncJobFailureSubscriber $subscriber */
        $subscriber = self::getContainer()->get(SyncJobFailureSubscriber::class);
        $subscriber->onMessageFailed($event);
        $this->em->clear();

        /** @var SyncJobRepository $repository */
        $repository = self::getContainer()->get(SyncJobRepository::class);
        $persisted = $repository->findByIdAndCompany($job->getId(), $companyId);

        self::assertNotNull($persisted);
        self::assertSame(SyncJobStatus::RUNNING, $persisted->getStatus());
        self::assertNull($persisted->getLastError());
    }

    public function testExhaustedRetryMarksJobFailedAndFinalizesParent(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $parent = $this->newBackfillJob($companyId);
        $parent->setProgressTotal(1);
        $parent->markRunning();

        $child = $this->newBackfillJob($companyId, $parent->getId());
        $child->markRunning();

        $this->em->persist($parent);
        $this->em->persist($child);
        $this->em->flush();

        $envelope = new Envelope(new RunSyncChunkMessage($companyId, $child->getId()));
        $previous = new ConnectorTransientException('Ozon timeout');
        $event = new WorkerMessageFailedEvent(
            $envelope,
            'ingest_fetch',
            new HandlerFailedException($envelope, [$previous]),
        );

        /** @var SyncJobFailureSubscriber $subscriber */
        $subscriber = self::getContainer()->get(SyncJobFailureSubscriber::class);
        $subscriber->onMessageFailed($event);
        $this->em->clear();

        /** @var SyncJobRepository $repository */
        $repository = self::getContainer()->get(SyncJobRepository::class);
        $persistedChild = $repository->findByIdAndCompany($child->getId(), $companyId);
        $persistedParent = $repository->findByIdAndCompany($parent->getId(), $companyId);

        self::assertNotNull($persistedChild);
        self::assertSame(SyncJobStatus::FAILED, $persistedChild->getStatus());
        self::assertSame(ConnectorTransientException::class.': Ozon timeout', $persistedChild->getLastError());

        self::assertNotNull($persistedParent);
        self::assertSame(SyncJobStatus::FAILED, $persistedParent->getStatus());
        self::assertSame(1, $persistedParent->getProgressDone());
        self::assertSame('partial failure: 1 failed, 0 cancelled, 0 completed', $persistedParent->getLastError());
    }

    private function newBackfillJob(string $companyId, ?string $parentJobId = null): SyncJob
    {
        return new SyncJob(
            companyId: $companyId,
            connectionRef: 'connection-1',
            source: IngestSource::WILDBERRIES,
            resourceType: FakeConnector::RESOURCE_TYPE,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-18'),
            windowTo: new \DateTimeImmutable('2026-06-18'),
            shopRef: 'shop-1',
            parentJobId: $parentJobId,
        );
    }
}
