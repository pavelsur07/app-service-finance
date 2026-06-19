<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Entity;

use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Enum\SyncJobStatus;
use App\Ingestion\Exception\SyncJobTransitionException;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class SyncJobTest extends TestCase
{
    public function testBackfillConstructorRequiresWindow(): void
    {
        $this->expectException(\DomainException::class);

        $this->createJob(kind: SyncJobKind::BACKFILL, windowFrom: null, windowTo: null);
    }

    public function testConstructorRejectsInvertedWindow(): void
    {
        $this->expectException(\DomainException::class);

        $this->createJob(
            windowFrom: new \DateTimeImmutable('2026-06-10'),
            windowTo: new \DateTimeImmutable('2026-06-01'),
        );
    }

    public function testIncrementalConstructorAllowsEmptyWindow(): void
    {
        $job = $this->createJob(kind: SyncJobKind::INCREMENTAL, windowFrom: null, windowTo: null);

        self::assertSame(SyncJobStatus::OPEN, $job->getStatus());
        self::assertNull($job->getWindowFrom());
        self::assertNull($job->getWindowTo());
    }

    public function testMarkRunningFromOpenStartsJobAndIncrementsAttempts(): void
    {
        $job = $this->createJob();

        $job->markRunning();

        self::assertSame(SyncJobStatus::RUNNING, $job->getStatus());
        self::assertSame(1, $job->getAttempts());
        self::assertNotNull($job->getStartedAt());
        self::assertNull($job->getFinishedAt());
    }

    public function testMarkRunningFromRunningIsRetryAndKeepsStartedAt(): void
    {
        $job = $this->createJob();
        $job->markRunning();
        $startedAt = $job->getStartedAt();

        usleep(1000);
        $job->markRunning();

        self::assertSame(SyncJobStatus::RUNNING, $job->getStatus());
        self::assertSame(2, $job->getAttempts());
        self::assertSame($startedAt, $job->getStartedAt());
    }

    public function testMarkCompletedRequiresRunningStatus(): void
    {
        $job = $this->createJob();

        $this->expectException(SyncJobTransitionException::class);

        $job->markCompleted();
    }

    public function testMarkCompletedFromRunningSetsTerminalState(): void
    {
        $job = $this->createJob();
        $finishedAt = new \DateTimeImmutable('2026-06-18 11:00:00');

        $job->markRunning();
        $job->markCompleted($finishedAt);

        self::assertSame(SyncJobStatus::COMPLETED, $job->getStatus());
        self::assertSame($finishedAt, $job->getFinishedAt());
        self::assertTrue($job->getStatus()->isTerminal());
    }

    public function testMarkFailedStoresReason(): void
    {
        $job = $this->createJob();
        $job->markRunning();

        $job->markFailed('Ozon returned 500');

        self::assertSame(SyncJobStatus::FAILED, $job->getStatus());
        self::assertSame('Ozon returned 500', $job->getLastError());
        self::assertNotNull($job->getFinishedAt());
    }

    public function testMarkCancelledFromOpenStoresReason(): void
    {
        $job = $this->createJob();

        $job->markCancelled('manual stop');

        self::assertSame(SyncJobStatus::CANCELLED, $job->getStatus());
        self::assertSame('manual stop', $job->getLastError());
        self::assertNotNull($job->getFinishedAt());
    }

    public function testTerminalStatusCannotTransition(): void
    {
        $job = $this->createJob();
        $job->markRunning();
        $job->markCompleted();

        $this->expectException(SyncJobTransitionException::class);

        $job->markRunning();
    }

    public function testProgressTotalCanBeSetOnlyOnce(): void
    {
        $job = $this->createJob();

        $job->setProgressTotal(3);

        self::assertSame(3, $job->getProgressTotal());

        $this->expectException(\DomainException::class);

        $job->setProgressTotal(4);
    }

    public function testIncrementProgressCannotExceedTotal(): void
    {
        $job = $this->createJob();
        $job->setProgressTotal(2);
        $job->incrementProgress();
        $job->incrementProgress();

        self::assertSame(2, $job->getProgressDone());

        $this->expectException(\DomainException::class);

        $job->incrementProgress();
    }

    public function testCursorSnapshotCanBeSetOnlyBeforeRunningAndOnce(): void
    {
        $job = $this->createJob();

        $job->setCursorSnapshot('cursor-1');

        self::assertSame('cursor-1', $job->getCursorSnapshot());

        $this->expectException(\DomainException::class);

        $job->setCursorSnapshot('cursor-2');
    }

    private function createJob(
        SyncJobKind $kind = SyncJobKind::BACKFILL,
        ?\DateTimeImmutable $windowFrom = new \DateTimeImmutable('2026-06-01'),
        ?\DateTimeImmutable $windowTo = new \DateTimeImmutable('2026-06-30'),
    ): SyncJob {
        return new SyncJob(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            source: IngestSource::OZON,
            resourceType: 'ozon_seller_daily_report',
            kind: $kind,
            windowFrom: $windowFrom,
            windowTo: $windowTo,
            shopRef: 'shop-1',
        );
    }
}
