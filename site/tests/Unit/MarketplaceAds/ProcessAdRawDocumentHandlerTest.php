<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\ProcessAdRawDocumentAction;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Exception\AdRawDocumentAlreadyProcessedException;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\MessageHandler\ProcessAdRawDocumentHandler;
use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Service\AppLogger;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sentry\State\HubInterface;

/**
 * Unit-тесты ProcessAdRawDocumentHandler.
 *
 * Что проверяем:
 *  - инкремент AdLoadJob.processedDays на PROCESSED-ветке;
 *  - инкремент AdLoadJob.failedDays на ветках Throwable и partial-DRAFT;
 *  - no-op, если по дате документа нет активного job'а (CLI-команда вне job-flow);
 *  - AdRawDocumentAlreadyProcessedException — race idempotency: НЕ инкрементим
 *    (инкремент сделает воркер, который реально обработал документ);
 *  - tryFinalizeJob условие: chunksCompleted>=chunksTotal AND
 *    (processedDays+failedDays) >= COUNT(AdRawDocument в диапазоне);
 *  - markCompleted при failedDays=0, markFailed с reason при failedDays>0;
 *  - финализация идемпотентна: второй tryFinalizeJob на COMPLETED job'е не
 *    вызывает markCompleted повторно.
 */
final class ProcessAdRawDocumentHandlerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const RAW_DOC_ID = '88888888-8888-8888-8888-888888888888';
    private const REPORT_DATE = '2026-03-15';

    public function testProcessedDocumentIncrementsJobProcessedDays(): void
    {
        $rawDoc = $this->newDraftDocument();
        $job = AdLoadJobBuilder::aJob()
            ->asRunning()
            ->withChunksTotal(5)
            ->withChunksCompleted(1) // чанки ещё не все закрыты — финализации не будет
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $this->newProcessedDocument());

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke')
            ->with(self::COMPANY_ID, self::RAW_DOC_ID);

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->expects(self::once())
            ->method('findActiveJobCoveringDate')
            ->with(self::COMPANY_ID, MarketplaceType::OZON, self::callback(static fn (\DateTimeImmutable $d): bool => self::REPORT_DATE === $d->format('Y-m-d')))
            ->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('incrementProcessedDays')
            ->with($job->getId(), self::COMPANY_ID)
            ->willReturn(1);
        $jobRepo->expects(self::never())->method('incrementFailedDays');
        $jobRepo->expects(self::once())->method('findFresh')->with($job->getId())->willReturn($job);
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::never())->method('markFailed');

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    public function testNoActiveJobForDateSkipsIncrement(): void
    {
        // Документ обработался, но активного job'а, покрывающего его дату, нет
        // (загружен CLI-командой вне orchestrator-flow). Основная ветка Action
        // отработала — Handler не должен инкрементить и не должен упасть.
        $rawDoc = $this->newDraftDocument();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $this->newProcessedDocument());

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->expects(self::once())
            ->method('findActiveJobCoveringDate')
            ->willReturn(null);
        $jobRepo->expects(self::never())->method('incrementProcessedDays');
        $jobRepo->expects(self::never())->method('incrementFailedDays');
        $jobRepo->expects(self::never())->method('findFresh');
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::never())->method('markFailed');

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    public function testFailedDocumentIncrementsJobFailedDaysAndRethrows(): void
    {
        $rawDoc = $this->newDraftDocument();
        $job = AdLoadJobBuilder::aJob()
            ->asRunning()
            ->withChunksTotal(5)
            ->withChunksCompleted(1)
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        // Only pre-read (до Action); после Throwable повторного pre/post read нет —
        // Handler сразу идёт в incrementFailedAndTryFinalize по сохранённому marketplace/date.
        $rawRepo->expects(self::once())
            ->method('findByIdAndCompany')
            ->with(self::RAW_DOC_ID, self::COMPANY_ID)
            ->willReturn($rawDoc);

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->method('__invoke')->willThrowException(new \RuntimeException('parser failure'));

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->expects(self::once())
            ->method('findActiveJobCoveringDate')
            ->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('incrementFailedDays')
            ->with($job->getId(), self::COMPANY_ID)
            ->willReturn(1);
        $jobRepo->expects(self::never())->method('incrementProcessedDays');
        $jobRepo->expects(self::once())->method('findFresh')->willReturn($job);
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::never())->method('markFailed'); // чанки ещё не все закрыты

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('parser failure');
        $handler($this->newMessage());
    }

    public function testPartialDraftAfterActionIncrementsFailedDays(): void
    {
        // Action не бросил исключение, но оставил документ в DRAFT (частичный
        // успех в ProcessAdRawDocumentAction: markAsProcessed() вызывается только
        // когда !hasErrors). С точки зрения job'а это failure — иначе условие
        // финализации (processed + failed == COUNT(raw)) никогда не сойдётся.
        $rawDoc = $this->newDraftDocument();
        $stillDraftAfter = $this->newDraftDocument();
        $job = AdLoadJobBuilder::aJob()
            ->asRunning()
            ->withChunksTotal(5)
            ->withChunksCompleted(1)
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $stillDraftAfter);

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->expects(self::once())
            ->method('findActiveJobCoveringDate')
            ->willReturn($job);
        $jobRepo->expects(self::once())
            ->method('incrementFailedDays')
            ->with($job->getId(), self::COMPANY_ID)
            ->willReturn(1);
        $jobRepo->expects(self::never())->method('incrementProcessedDays');
        $jobRepo->expects(self::once())->method('findFresh')->willReturn($job);

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    public function testAlreadyProcessedExceptionDoesNotIncrementCounters(): void
    {
        // Специфическая гонка: между pre-check в handler'е и вызовом Action
        // другой воркер успел обработать документ → Action бросает
        // AdRawDocumentAlreadyProcessedException. Этот воркер НЕ инкрементит
        // processed/failed — это сделает тот, кто реально обработал.
        $rawDoc = $this->newDraftDocument();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->expects(self::once())
            ->method('findByIdAndCompany')
            ->willReturn($rawDoc);

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->method('__invoke')->willThrowException(new AdRawDocumentAlreadyProcessedException('already processed'));

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->expects(self::never())->method('findActiveJobCoveringDate');
        $jobRepo->expects(self::never())->method('incrementProcessedDays');
        $jobRepo->expects(self::never())->method('incrementFailedDays');
        $jobRepo->expects(self::never())->method('findFresh');
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::never())->method('markFailed');

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    public function testJobFinalizedAsCompletedWhenAllConditionsMet(): void
    {
        $rawDoc = $this->newDraftDocument();
        // После incrementProcessedDays → find() возвращает job в свежем состоянии:
        // chunksCompleted == chunksTotal, processedDays=10, failedDays=0, COUNT(raw)=10.
        $freshJob = AdLoadJobBuilder::aJob()
            ->asRunning()
            ->withChunksTotal(5)
            ->withChunksCompleted(5)
            ->withProcessed(10)
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $this->newProcessedDocument());
        $rawRepo->expects(self::once())
            ->method('countByCompanyMarketplaceAndDateRange')
            ->willReturn(10);

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->method('findActiveJobCoveringDate')->willReturn($freshJob);
        $jobRepo->expects(self::once())->method('incrementProcessedDays')->willReturn(1);
        $jobRepo->method('findFresh')->with($freshJob->getId())->willReturn($freshJob);
        $jobRepo->expects(self::once())
            ->method('markCompleted')
            ->with($freshJob->getId(), self::COMPANY_ID)
            ->willReturn(1);
        $jobRepo->expects(self::never())->method('markFailed');

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    public function testJobFinalizedAsFailedWhenHasFailures(): void
    {
        $rawDoc = $this->newDraftDocument();
        $freshJob = AdLoadJobBuilder::aJob()
            ->asRunning()
            ->withChunksTotal(5)
            ->withChunksCompleted(5)
            ->withProcessed(8)
            ->withFailed(2)
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $this->newProcessedDocument());
        $rawRepo->expects(self::once())
            ->method('countByCompanyMarketplaceAndDateRange')
            ->willReturn(10);

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->method('findActiveJobCoveringDate')->willReturn($freshJob);
        $jobRepo->expects(self::once())->method('incrementProcessedDays')->willReturn(1);
        $jobRepo->method('findFresh')->willReturn($freshJob);
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(
                $freshJob->getId(),
                self::COMPANY_ID,
                self::callback(static fn (string $reason): bool => str_contains($reason, '2/10') && str_contains($reason, 'Partial failure')),
            )
            ->willReturn(1);

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    public function testJobNotFinalizedWhenChunksIncomplete(): void
    {
        $rawDoc = $this->newDraftDocument();
        $job = AdLoadJobBuilder::aJob()
            ->asRunning()
            ->withChunksTotal(5)
            ->withChunksCompleted(3) // не все чанки закрыты
            ->withProcessed(10)
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $this->newProcessedDocument());
        // Если chunks incomplete — COUNT не запрашиваем (ранний return).
        $rawRepo->expects(self::never())->method('countByCompanyMarketplaceAndDateRange');

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->method('findActiveJobCoveringDate')->willReturn($job);
        $jobRepo->expects(self::once())->method('incrementProcessedDays')->willReturn(1);
        $jobRepo->method('findFresh')->willReturn($job);
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::never())->method('markFailed');

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    public function testJobNotFinalizedWhenDocumentsIncomplete(): void
    {
        $rawDoc = $this->newDraftDocument();
        // chunks done, но processedDays=8, failedDays=0, COUNT(raw)=10 → ещё не
        // все документы дошли до PROCESSED (или failed).
        $job = AdLoadJobBuilder::aJob()
            ->asRunning()
            ->withChunksTotal(5)
            ->withChunksCompleted(5)
            ->withProcessed(8)
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $this->newProcessedDocument());
        $rawRepo->expects(self::once())
            ->method('countByCompanyMarketplaceAndDateRange')
            ->willReturn(10);

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->method('findActiveJobCoveringDate')->willReturn($job);
        $jobRepo->expects(self::once())->method('incrementProcessedDays')->willReturn(1);
        $jobRepo->method('findFresh')->willReturn($job);
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::never())->method('markFailed');

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    public function testFinalizationRaceDoesNotDoubleMark(): void
    {
        // Два воркера обрабатывают два последних документа параллельно. Оба
        // доходят до tryFinalizeJob. Второй увидит job уже в COMPLETED (т.к.
        // markCompleted первого voркера прошёл и внутри SQL-guard'а второй
        // UPDATE затронет 0 строк — но до этого мы даже не дойдём, т.к. guard
        // на статус в начале tryFinalizeJob вернёт рано).
        $rawDoc = $this->newDraftDocument();

        $runningJob = AdLoadJobBuilder::aJob()
            ->asRunning()
            ->withChunksTotal(5)
            ->withChunksCompleted(5)
            ->withProcessed(10)
            ->build();
        $completedJob = AdLoadJobBuilder::aJob()
            ->asCompleted()
            ->withChunksTotal(5)
            ->withChunksCompleted(5)
            ->withProcessed(10)
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $this->newProcessedDocument());
        // countByRange зовётся при первом tryFinalize (status=RUNNING), при
        // втором (status=COMPLETED) происходит ранний return до COUNT — но мы
        // тестируем в одном __invoke один воркер, проверяем что find() возвращает
        // COMPLETED job и markCompleted НЕ зовётся повторно.
        $rawRepo->expects(self::never())->method('countByCompanyMarketplaceAndDateRange');

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->method('findActiveJobCoveringDate')->willReturn($runningJob);
        $jobRepo->expects(self::once())->method('incrementProcessedDays')->willReturn(1);
        // Сценарий: пока мы инкрементили, другой воркер уже финализировал job'а.
        // find() возвращает уже COMPLETED job, tryFinalizeJob делает early return.
        $jobRepo->method('findFresh')->willReturn($completedJob);
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::never())->method('markFailed');

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    private function newDraftDocument(): AdRawDocument
    {
        return AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable(self::REPORT_DATE))
            ->build();
    }

    private function newProcessedDocument(): AdRawDocument
    {
        return AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable(self::REPORT_DATE))
            ->asProcessed()
            ->build();
    }

    private function newMessage(): ProcessAdRawDocumentMessage
    {
        return new ProcessAdRawDocumentMessage(self::COMPANY_ID, self::RAW_DOC_ID);
    }

    private function wireRawRepoPrePost(
        \PHPUnit\Framework\MockObject\MockObject $rawRepo,
        AdRawDocument $pre,
        AdRawDocument $post,
    ): void {
        // Handler зовёт findByIdAndCompany дважды на happy/partial-path: перед
        // Action'ом (для pre-check DRAFT) и после (для проверки PROCESSED/DRAFT).
        $rawRepo->expects(self::exactly(2))
            ->method('findByIdAndCompany')
            ->with(self::RAW_DOC_ID, self::COMPANY_ID)
            ->willReturnOnConsecutiveCalls($pre, $post);
    }

    private function createHandler(
        AdRawDocumentRepository $rawRepo,
        ProcessAdRawDocumentAction $action,
        AdLoadJobRepositoryInterface $jobRepo,
    ): ProcessAdRawDocumentHandler {
        return new ProcessAdRawDocumentHandler(
            $rawRepo,
            $action,
            $jobRepo,
            new AppLogger(new NullLogger(), $this->createMock(HubInterface::class)),
        );
    }
}
