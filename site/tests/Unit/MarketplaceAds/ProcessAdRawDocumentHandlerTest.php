<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\ProcessAdRawDocumentAction;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Exception\AdRawDocumentAlreadyProcessedException;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\MessageHandler\ProcessAdRawDocumentHandler;
use App\MarketplaceAds\Repository\AdChunkProgressRepositoryInterface;
use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use App\MarketplaceAds\Repository\AdRawDocumentRepositoryInterface;
use App\Shared\Service\AppLogger;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
use DG\BypassFinals;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sentry\State\HubInterface;

// Bootstrap pins BypassFinals to an allowlist; extend it so the action under test
// can be doubled without touching the global bootstrap configuration.
BypassFinals::allowPaths([
    '*/src/MarketplaceAds/Application/ProcessAdRawDocumentAction.php',
]);

/**
 * Unit-тесты {@see ProcessAdRawDocumentHandler}: per-document FAILED + финализация job'а.
 *
 * Покрываемые инварианты:
 *  - PROCESSED-документ запускает попытку финализации.
 *  - DRAFT после Action → markFailedWithReason + return без throw.
 *  - Исключение из Action → markFailedWithReason + tryFinalize + rethrow.
 *  - AdRawDocumentAlreadyProcessedException → swallow, без вызова финализации.
 *  - Финализация: COMPLETED, если failed=0; FAILED с reason, если failed>0.
 *  - Финализация пропускается, если completedChunks < chunksTotal или есть DRAFT-документы.
 *  - Нет активного job на дату → tryFinalize выходит молча.
 *  - Race idempotency: markCompleted вернул 0 → info-лог не пишется.
 */
final class ProcessAdRawDocumentHandlerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const DOCUMENT_ID = '88888888-8888-8888-8888-888888888888';
    private const JOB_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    /** @var ProcessAdRawDocumentAction&MockObject */
    private ProcessAdRawDocumentAction $action;
    /** @var AdRawDocumentRepositoryInterface&MockObject */
    private AdRawDocumentRepositoryInterface $rawDocRepo;
    /** @var AdLoadJobRepositoryInterface&MockObject */
    private AdLoadJobRepositoryInterface $jobRepo;
    /** @var AdChunkProgressRepositoryInterface&MockObject */
    private AdChunkProgressRepositoryInterface $chunkRepo;
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $entityManager;
    private AppLogger $logger;
    private ProcessAdRawDocumentHandler $handler;

    protected function setUp(): void
    {
        $this->action = $this->createMock(ProcessAdRawDocumentAction::class);
        $this->rawDocRepo = $this->createMock(AdRawDocumentRepositoryInterface::class);
        $this->jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $this->chunkRepo = $this->createMock(AdChunkProgressRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = new AppLogger(new NullLogger(), $this->createMock(HubInterface::class));

        $this->handler = new ProcessAdRawDocumentHandler(
            $this->action,
            $this->rawDocRepo,
            $this->jobRepo,
            $this->chunkRepo,
            $this->entityManager,
            $this->logger,
        );
    }

    public function testProcessedDocumentTriggersFinalizationCheck(): void
    {
        $document = $this->buildProcessedDocument();
        $job = $this->buildRunningJob(chunksTotal: 1);

        $this->action->expects(self::once())
            ->method('__invoke')
            ->with(self::COMPANY_ID, self::DOCUMENT_ID);
        $this->entityManager->expects(self::once())->method('flush');

        // findByIdAndCompany вызывается дважды: после flush() в главном потоке
        // и внутри tryFinalizeJobForDocument при перечитывании.
        $this->rawDocRepo->expects(self::exactly(2))
            ->method('findByIdAndCompany')
            ->with(self::DOCUMENT_ID, self::COMPANY_ID)
            ->willReturn($document);
        $this->rawDocRepo->expects(self::never())->method('markFailedWithReason');

        $this->jobRepo->expects(self::once())
            ->method('findActiveJobCoveringDate')
            ->willReturn($job);
        $this->jobRepo->expects(self::once())
            ->method('findByIdAndCompany')
            ->with(self::JOB_ID, self::COMPANY_ID)
            ->willReturn($job);

        $this->chunkRepo->expects(self::once())
            ->method('countCompletedChunks')
            ->willReturn(1);

        // chunks complete → читаем COUNT по документам.
        $this->mockDocCounts(total: 1, processed: 1, failed: 0);

        $this->jobRepo->expects(self::once())
            ->method('markCompleted')
            ->with(self::JOB_ID, self::COMPANY_ID)
            ->willReturn(1);

        ($this->handler)(new ProcessAdRawDocumentMessage(self::COMPANY_ID, self::DOCUMENT_ID));
    }

    public function testDraftAfterActionIsMarkedFailedAndReturnsWithoutThrow(): void
    {
        $draftDocument = $this->buildDraftDocument();
        $failedDocument = $this->buildFailedDocument();

        $this->action->expects(self::once())->method('__invoke');
        $this->entityManager->expects(self::once())->method('flush');

        // Первый findByIdAndCompany возвращает DRAFT-документ; второй (внутри
        // tryFinalizeJobForDocument) — уже FAILED-документ (статус мутировал в БД).
        $this->rawDocRepo->expects(self::exactly(2))
            ->method('findByIdAndCompany')
            ->willReturnOnConsecutiveCalls($draftDocument, $failedDocument);

        $this->rawDocRepo->expects(self::once())
            ->method('markFailedWithReason')
            ->with(
                self::DOCUMENT_ID,
                self::COMPANY_ID,
                'Action left document in DRAFT (partial processing failure)',
            )
            ->willReturn(1);

        // Job не найден на дату → tryFinalize выходит no-op'ом, но он реально вызывается.
        $this->jobRepo->expects(self::once())
            ->method('findActiveJobCoveringDate')
            ->willReturn(null);

        ($this->handler)(new ProcessAdRawDocumentMessage(self::COMPANY_ID, self::DOCUMENT_ID));
    }

    public function testExceptionMarksDocumentFailedAndRethrows(): void
    {
        $exception = new \RuntimeException('boom');

        $this->action->expects(self::once())
            ->method('__invoke')
            ->willThrowException($exception);

        $this->entityManager->expects(self::never())->method('flush');

        $this->rawDocRepo->expects(self::once())
            ->method('markFailedWithReason')
            ->with(
                self::DOCUMENT_ID,
                self::COMPANY_ID,
                'RuntimeException: boom',
            )
            ->willReturn(1);

        // tryFinalizeJobForDocument внутри catch — документа уже нет (мок вернёт null).
        $this->rawDocRepo->expects(self::once())
            ->method('findByIdAndCompany')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        ($this->handler)(new ProcessAdRawDocumentMessage(self::COMPANY_ID, self::DOCUMENT_ID));
    }

    public function testAlreadyProcessedExceptionIsSwallowedWithoutFinalize(): void
    {
        $this->action->expects(self::once())
            ->method('__invoke')
            ->willThrowException(new AdRawDocumentAlreadyProcessedException('race'));

        $this->entityManager->expects(self::never())->method('flush');
        $this->rawDocRepo->expects(self::never())->method('markFailedWithReason');
        $this->rawDocRepo->expects(self::never())->method('findByIdAndCompany');
        $this->jobRepo->expects(self::never())->method('findActiveJobCoveringDate');
        $this->jobRepo->expects(self::never())->method('findByIdAndCompany');
        $this->chunkRepo->expects(self::never())->method('countCompletedChunks');

        ($this->handler)(new ProcessAdRawDocumentMessage(self::COMPANY_ID, self::DOCUMENT_ID));
    }

    public function testFinalizationMarksCompletedWhenAllProcessedNoFailures(): void
    {
        $document = $this->buildProcessedDocument();
        $job = $this->buildRunningJob(chunksTotal: 2);

        $this->primeSuccessPath($document, $job);
        $this->chunkRepo->method('countCompletedChunks')->willReturn(2);
        $this->mockDocCounts(total: 5, processed: 5, failed: 0);

        $this->jobRepo->expects(self::once())
            ->method('markCompleted')
            ->with(self::JOB_ID, self::COMPANY_ID)
            ->willReturn(1);
        $this->jobRepo->expects(self::never())->method('markFailed');

        ($this->handler)(new ProcessAdRawDocumentMessage(self::COMPANY_ID, self::DOCUMENT_ID));
    }

    public function testFinalizationMarksFailedWhenSomeDocsFailed(): void
    {
        $document = $this->buildProcessedDocument();
        $job = $this->buildRunningJob(chunksTotal: 2);

        $this->primeSuccessPath($document, $job);
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

        ($this->handler)(new ProcessAdRawDocumentMessage(self::COMPANY_ID, self::DOCUMENT_ID));
    }

    public function testFinalizationSkippedWhenChunksIncomplete(): void
    {
        $document = $this->buildProcessedDocument();
        $job = $this->buildRunningJob(chunksTotal: 3);

        $this->primeSuccessPath($document, $job);
        // Зафиксировано 2 из 3 чанков — рано финализировать.
        $this->chunkRepo->expects(self::once())
            ->method('countCompletedChunks')
            ->willReturn(2);

        $this->rawDocRepo->expects(self::never())->method('countByCompanyMarketplaceAndDateRange');
        $this->jobRepo->expects(self::never())->method('markCompleted');
        $this->jobRepo->expects(self::never())->method('markFailed');

        ($this->handler)(new ProcessAdRawDocumentMessage(self::COMPANY_ID, self::DOCUMENT_ID));
    }

    public function testFinalizationSkippedWhenDraftsRemain(): void
    {
        $document = $this->buildProcessedDocument();
        $job = $this->buildRunningJob(chunksTotal: 1);

        $this->primeSuccessPath($document, $job);
        $this->chunkRepo->method('countCompletedChunks')->willReturn(1);
        // 5 всего, 3 processed + 1 failed = 4 терминальных, остался 1 DRAFT.
        $this->mockDocCounts(total: 5, processed: 3, failed: 1);

        $this->jobRepo->expects(self::never())->method('markCompleted');
        $this->jobRepo->expects(self::never())->method('markFailed');

        ($this->handler)(new ProcessAdRawDocumentMessage(self::COMPANY_ID, self::DOCUMENT_ID));
    }

    public function testNoActiveJobForDateSkipsFinalize(): void
    {
        $document = $this->buildProcessedDocument();

        $this->action->expects(self::once())->method('__invoke');
        $this->entityManager->expects(self::once())->method('flush');

        $this->rawDocRepo->expects(self::exactly(2))
            ->method('findByIdAndCompany')
            ->willReturn($document);

        $this->jobRepo->expects(self::once())
            ->method('findActiveJobCoveringDate')
            ->willReturn(null);
        $this->jobRepo->expects(self::never())->method('findByIdAndCompany');
        $this->chunkRepo->expects(self::never())->method('countCompletedChunks');
        $this->jobRepo->expects(self::never())->method('markCompleted');
        $this->jobRepo->expects(self::never())->method('markFailed');

        ($this->handler)(new ProcessAdRawDocumentMessage(self::COMPANY_ID, self::DOCUMENT_ID));
    }

    public function testFinalizationRaceIdempotency(): void
    {
        // Параллельный воркер уже перевёл job в COMPLETED → markCompleted вернул 0,
        // info-лог не должен записаться. PHPUnit не умеет ассертить «логгер не вызван»
        // напрямую через NullLogger, поэтому проверяем конкретную ветку:
        // markCompleted вернул 0, метод вернулся без исключения, markFailed не вызывался.
        $document = $this->buildProcessedDocument();
        $job = $this->buildRunningJob(chunksTotal: 1);

        $this->primeSuccessPath($document, $job);
        $this->chunkRepo->method('countCompletedChunks')->willReturn(1);
        $this->mockDocCounts(total: 1, processed: 1, failed: 0);

        $this->jobRepo->expects(self::once())
            ->method('markCompleted')
            ->willReturn(0);
        $this->jobRepo->expects(self::never())->method('markFailed');

        ($this->handler)(new ProcessAdRawDocumentMessage(self::COMPANY_ID, self::DOCUMENT_ID));
    }

    /**
     * Подготавливает успешный happy-path: action ок, flush ок, документ перечитан как PROCESSED,
     * job найден по дате и поднят по ID.
     */
    private function primeSuccessPath(object $document, object $job): void
    {
        $this->action->expects(self::once())->method('__invoke');
        $this->entityManager->expects(self::once())->method('flush');

        $this->rawDocRepo->expects(self::exactly(2))
            ->method('findByIdAndCompany')
            ->willReturn($document);

        $this->jobRepo->expects(self::once())
            ->method('findActiveJobCoveringDate')
            ->willReturn($job);
        $this->jobRepo->expects(self::once())
            ->method('findByIdAndCompany')
            ->with(self::JOB_ID, self::COMPANY_ID)
            ->willReturn($job);
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

    private function buildDraftDocument(): object
    {
        return AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-03-05'))
            ->build();
    }

    private function buildProcessedDocument(): object
    {
        return AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-03-05'))
            ->asProcessed()
            ->build();
    }

    private function buildFailedDocument(): object
    {
        return AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-03-05'))
            ->asFailed('Action left document in DRAFT (partial processing failure)')
            ->build();
    }

    private function buildRunningJob(int $chunksTotal): object
    {
        return AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withDateRange(
                new \DateTimeImmutable('2026-03-01'),
                new \DateTimeImmutable('2026-03-10'),
            )
            ->withChunksTotal($chunksTotal)
            ->asRunning()
            ->build();
    }
}
