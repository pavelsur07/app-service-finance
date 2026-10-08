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
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

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

    public function testExhaustedTransientFailureOptsOutOfFailureTransport(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $job = $this->newBackfillJob($companyId);
        $job->markRunning();
        $this->em->persist($job);
        $this->em->flush();

        $envelope = new Envelope(new RunSyncChunkMessage($companyId, $job->getId()));
        $event = new WorkerMessageFailedEvent(
            $envelope,
            'ingest_fetch',
            new HandlerFailedException($envelope, [new ConnectorTransientException('WB orders server error (HTTP 500)')]),
        );

        /** @var SyncJobFailureSubscriber $subscriber */
        $subscriber = self::getContainer()->get(SyncJobFailureSubscriber::class);
        $subscriber->onMessageFailed($event);
        $this->em->clear();

        $persisted = self::getContainer()->get(SyncJobRepository::class)->findByIdAndCompany($job->getId(), $companyId);
        self::assertSame(SyncJobStatus::FAILED, $persisted?->getStatus());

        $stamp = $event->getEnvelope()->last(SentToFailureTransportStamp::class);
        self::assertInstanceOf(SentToFailureTransportStamp::class, $stamp);
        self::assertSame('ingest_fetch', $stamp->getOriginalReceiverName());
    }

    public function testExhaustedNonTransientFailureStillGoesToFailureTransport(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $job = $this->newBackfillJob($companyId);
        $job->markRunning();
        $this->em->persist($job);
        $this->em->flush();

        $envelope = new Envelope(new RunSyncChunkMessage($companyId, $job->getId()));
        $event = new WorkerMessageFailedEvent(
            $envelope,
            'ingest_fetch',
            new HandlerFailedException($envelope, [new \RuntimeException('bug')]),
        );

        /** @var SyncJobFailureSubscriber $subscriber */
        $subscriber = self::getContainer()->get(SyncJobFailureSubscriber::class);
        $subscriber->onMessageFailed($event);

        self::assertNull($event->getEnvelope()->last(SentToFailureTransportStamp::class));
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

    /**
     * Регрессия #230/#384: разовый 5xx маркетплейса больше не событие GlitchTip,
     * поэтому затяжной сбой обязан дать свой сигнал — ровно один `error` на
     * пороге серии, и ни одного до него и после.
     */
    public function testSustainedTransientOutageLogsOneErrorAtThreshold(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $logHandler = $this->logHandler();

        $errorsAfter = [];
        for ($i = 1; $i <= SyncJobFailureSubscriber::TRANSIENT_STREAK_ALERT + 1; ++$i) {
            $this->exhaustWithTransientFailure($companyId);
            $errorsAfter[$i] = $this->errorCount($logHandler);
        }

        self::assertSame(0, $errorsAfter[SyncJobFailureSubscriber::TRANSIENT_STREAK_ALERT - 1], 'До порога — только warning');
        self::assertSame(1, $errorsAfter[SyncJobFailureSubscriber::TRANSIENT_STREAK_ALERT]);
        self::assertSame(1, $errorsAfter[SyncJobFailureSubscriber::TRANSIENT_STREAK_ALERT + 1], 'Серия не будит повторно на каждом отказе');
        self::assertTrue($logHandler->hasErrorThatContains('Marketplace API keeps failing for a sync resource'));
    }

    public function testSuccessfulJobBreaksTheTransientStreak(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $logHandler = $this->logHandler();

        $this->exhaustWithTransientFailure($companyId);
        $this->exhaustWithTransientFailure($companyId);

        $ok = $this->newBackfillJob($companyId);
        $ok->markRunning();
        $ok->markCompleted();
        $this->em->persist($ok);
        $this->em->flush();

        $this->exhaustWithTransientFailure($companyId);
        $this->exhaustWithTransientFailure($companyId);

        self::assertSame(0, $this->errorCount($logHandler));
    }

    public function testTransientConnectorFailuresAreNotSentToGlitchTip(): void
    {
        /** @var \Sentry\Options $options */
        $options = self::getContainer()->get('sentry.client.options');

        self::assertContains(ConnectorTransientException::class, $options->getIgnoreExceptions());
    }

    private function exhaustWithTransientFailure(string $companyId): void
    {
        $job = $this->newBackfillJob($companyId);
        $job->markRunning();
        $this->em->persist($job);
        $this->em->flush();

        $envelope = new Envelope(new RunSyncChunkMessage($companyId, $job->getId()));
        $event = new WorkerMessageFailedEvent(
            $envelope,
            'ingest_fetch',
            new HandlerFailedException($envelope, [new ConnectorTransientException('WB orders server error (HTTP 500)')]),
        );

        /** @var SyncJobFailureSubscriber $subscriber */
        $subscriber = self::getContainer()->get(SyncJobFailureSubscriber::class);
        $subscriber->onMessageFailed($event);
    }

    private function logHandler(): TestHandler
    {
        /** @var TestHandler $handler */
        $handler = self::getContainer()->get(TestHandler::class);
        $handler->clear();

        return $handler;
    }

    private function errorCount(TestHandler $handler): int
    {
        return count(array_filter($handler->getRecords(), static fn ($r): bool => $r->level->value >= Level::Error->value));
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
