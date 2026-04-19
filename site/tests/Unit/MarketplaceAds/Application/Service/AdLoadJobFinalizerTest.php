<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Application\Service;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\Service\AdLoadJobFinalizer;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Repository\AdChunkProgressRepositoryInterface;
use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use App\MarketplaceAds\Repository\AdRawDocumentRepositoryInterface;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-тесты {@see AdLoadJobFinalizer}: логика идемпотентной финализации job'а.
 *
 * Покрываемые инварианты:
 *  - Job отсутствует / не RUNNING → no-op.
 *  - completedChunks < chunksTotal → no-op (рано финализировать).
 *  - Есть DRAFT-документы (processed+failed < total) → no-op.
 *  - Все документы processed, 0 failed → markCompleted + info-лог.
 *  - Хотя бы один failed → markFailed с reason + warning-лог.
 *  - Race: markCompleted вернул 0 → info-лог не пишется, исключений нет.
 *  - 0 документов за период (Ozon вернул пусто): total=0 → markCompleted.
 */
final class AdLoadJobFinalizerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const JOB_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    /** @var AdLoadJobRepositoryInterface&MockObject */
    private AdLoadJobRepositoryInterface $jobRepo;
    /** @var AdRawDocumentRepositoryInterface&MockObject */
    private AdRawDocumentRepositoryInterface $rawDocRepo;
    /** @var AdChunkProgressRepositoryInterface&MockObject */
    private AdChunkProgressRepositoryInterface $chunkRepo;
    private AdLoadJobFinalizer $finalizer;

    protected function setUp(): void
    {
        $this->jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $this->rawDocRepo = $this->createMock(AdRawDocumentRepositoryInterface::class);
        $this->chunkRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);

        $this->finalizer = new AdLoadJobFinalizer(
            $this->jobRepo,
            $this->rawDocRepo,
            $this->chunkRepo,
            new NullLogger(),
        );
    }

    public function testJobNotFoundIsNoOp(): void
    {
        $this->jobRepo->expects(self::once())
            ->method('findByIdAndCompany')
            ->with(self::JOB_ID, self::COMPANY_ID)
            ->willReturn(null);

        $this->chunkRepo->expects(self::never())->method('countCompletedChunks');
        $this->rawDocRepo->expects(self::never())->method('countByCompanyMarketplaceAndDateRange');
        $this->jobRepo->expects(self::never())->method('markCompleted');
        $this->jobRepo->expects(self::never())->method('markFailed');

        $this->finalizer->tryFinalize(self::JOB_ID, self::COMPANY_ID);
    }

    public function testTerminalJobIsNoOp(): void
    {
        $job = $this->buildJob()->asCompleted()->build();

        $this->jobRepo->method('findByIdAndCompany')->willReturn($job);

        $this->chunkRepo->expects(self::never())->method('countCompletedChunks');
        $this->rawDocRepo->expects(self::never())->method('countByCompanyMarketplaceAndDateRange');
        $this->jobRepo->expects(self::never())->method('markCompleted');
        $this->jobRepo->expects(self::never())->method('markFailed');

        $this->finalizer->tryFinalize(self::JOB_ID, self::COMPANY_ID);
    }

    public function testChunksIncompleteIsNoOp(): void
    {
        $job = $this->buildJob()->withChunksTotal(3)->asRunning()->build();

        $this->jobRepo->method('findByIdAndCompany')->willReturn($job);
        $this->chunkRepo->expects(self::once())
            ->method('countCompletedChunks')
            ->willReturn(2);

        $this->rawDocRepo->expects(self::never())->method('countByCompanyMarketplaceAndDateRange');
        $this->jobRepo->expects(self::never())->method('markCompleted');
        $this->jobRepo->expects(self::never())->method('markFailed');

        $this->finalizer->tryFinalize(self::JOB_ID, self::COMPANY_ID);
    }

    public function testDraftsRemainingIsNoOp(): void
    {
        $job = $this->buildJob()->withChunksTotal(1)->asRunning()->build();

        $this->jobRepo->method('findByIdAndCompany')->willReturn($job);
        $this->chunkRepo->method('countCompletedChunks')->willReturn(1);
        // 5 total, 3 processed + 1 failed = 4 терминальных, остался 1 DRAFT.
        $this->mockDocCounts(total: 5, processed: 3, failed: 1);

        $this->jobRepo->expects(self::never())->method('markCompleted');
        $this->jobRepo->expects(self::never())->method('markFailed');

        $this->finalizer->tryFinalize(self::JOB_ID, self::COMPANY_ID);
    }

    public function testMarkCompletedWhenAllProcessedNoFailures(): void
    {
        $job = $this->buildJob()->withChunksTotal(2)->asRunning()->build();

        $this->jobRepo->method('findByIdAndCompany')->willReturn($job);
        $this->chunkRepo->method('countCompletedChunks')->willReturn(2);
        $this->mockDocCounts(total: 5, processed: 5, failed: 0);

        $this->jobRepo->expects(self::once())
            ->method('markCompleted')
            ->with(self::JOB_ID, self::COMPANY_ID)
            ->willReturn(1);
        $this->jobRepo->expects(self::never())->method('markFailed');

        $this->finalizer->tryFinalize(self::JOB_ID, self::COMPANY_ID);
    }

    public function testMarkFailedWhenSomeDocsFailed(): void
    {
        $job = $this->buildJob()->withChunksTotal(2)->asRunning()->build();

        $this->jobRepo->method('findByIdAndCompany')->willReturn($job);
        $this->chunkRepo->method('countCompletedChunks')->willReturn(2);
        $this->mockDocCounts(total: 5, processed: 3, failed: 2);

        $this->jobRepo->expects(self::never())->method('markCompleted');
        $this->jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(
                self::JOB_ID,
                self::COMPANY_ID,
                'Partial failure: 2 of 5 documents failed',
            )
            ->willReturn(1);

        $this->finalizer->tryFinalize(self::JOB_ID, self::COMPANY_ID);
    }

    public function testRaceIdempotencyMarkCompletedReturnsZero(): void
    {
        // Параллельный воркер уже перевёл job в COMPLETED → markCompleted вернул 0.
        // Метод обязан завершиться без исключения, markFailed не вызывается.
        $job = $this->buildJob()->withChunksTotal(1)->asRunning()->build();

        $this->jobRepo->method('findByIdAndCompany')->willReturn($job);
        $this->chunkRepo->method('countCompletedChunks')->willReturn(1);
        $this->mockDocCounts(total: 1, processed: 1, failed: 0);

        $this->jobRepo->expects(self::once())
            ->method('markCompleted')
            ->willReturn(0);
        $this->jobRepo->expects(self::never())->method('markFailed');

        $this->finalizer->tryFinalize(self::JOB_ID, self::COMPANY_ID);
    }

    public function testZeroDocumentsTriggersMarkCompleted(): void
    {
        // Ozon вернул пусто за весь период → ProcessAdRawDocumentHandler никогда
        // не запустится; финализация должна случиться из FetchOzonAdStatisticsHandler
        // при total=0. processed+failed (0) == total (0), failed=0 → markCompleted.
        $job = $this->buildJob()->withChunksTotal(1)->asRunning()->build();

        $this->jobRepo->method('findByIdAndCompany')->willReturn($job);
        $this->chunkRepo->method('countCompletedChunks')->willReturn(1);
        $this->mockDocCounts(total: 0, processed: 0, failed: 0);

        $this->jobRepo->expects(self::once())
            ->method('markCompleted')
            ->with(self::JOB_ID, self::COMPANY_ID)
            ->willReturn(1);
        $this->jobRepo->expects(self::never())->method('markFailed');

        $this->finalizer->tryFinalize(self::JOB_ID, self::COMPANY_ID);
    }

    private function mockDocCounts(int $total, int $processed, int $failed): void
    {
        $this->rawDocRepo->expects(self::exactly(3))
            ->method('countByCompanyMarketplaceAndDateRange')
            ->willReturnCallback(
                static function (
                    string $companyId,
                    string $marketplace,
                    \DateTimeImmutable $from,
                    \DateTimeImmutable $to,
                    ?AdRawDocumentStatus $status = null,
                ) use ($total, $processed, $failed): int {
                    return match ($status) {
                        null => $total,
                        AdRawDocumentStatus::PROCESSED => $processed,
                        AdRawDocumentStatus::FAILED => $failed,
                        default => 0,
                    };
                },
            );
    }

    private function buildJob(): AdLoadJobBuilder
    {
        return AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withDateRange(
                new \DateTimeImmutable('2026-03-01'),
                new \DateTimeImmutable('2026-03-10'),
            );
    }
}
