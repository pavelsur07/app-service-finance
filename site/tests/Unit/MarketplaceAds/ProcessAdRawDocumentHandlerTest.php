<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\ProcessAdRawDocumentAction;
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
 * Инварианты после перехода на per-document FAILED-статус:
 *  - PROCESSED после Action → сразу tryFinalizeJob по дате документа.
 *  - Throwable → markFailedWithReason (атомарный SQL) + tryFinalizeJob + rethrow.
 *  - DRAFT после Action (partial success) → markFailedWithReason + tryFinalizeJob,
 *    БЕЗ throw (Action транзакцию не откатил, повторять нечего).
 *  - AdRawDocumentAlreadyProcessedException (гонка воркеров) → silent return,
 *    без mark и без финализации: документ уже терминален, job финализирует
 *    другой воркер или retry.
 *  - Нет активного job'а по дате → ни mark, ни финализации не происходит.
 *  - Финализация: chunks completed AND total == processed + failed;
 *    failed == 0 → markCompleted, else markFailed(reason).
 */
final class ProcessAdRawDocumentHandlerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const RAW_DOC_ID = '88888888-8888-8888-8888-888888888888';
    private const REPORT_DATE = '2026-03-15';

    public function testProcessedDocumentTriggersFinalizationAttemptWithoutIncrement(): void
    {
        $rawDoc = $this->newDraftDocument();
        $job = AdLoadJobBuilder::aJob()
            ->asRunning()
            ->withChunksTotal(5)
            ->withChunksCompleted(1) // ещё не все чанки — финализация выйдет рано
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $this->newProcessedDocument());
        $rawRepo->expects(self::never())->method('markFailedWithReason');
        $rawRepo->expects(self::never())->method('countByCompanyMarketplaceAndDateRange');
        $rawRepo->expects(self::never())->method('countTerminalByCompanyMarketplaceAndDateRange');

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke')
            ->with(self::COMPANY_ID, self::RAW_DOC_ID);

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->expects(self::once())
            ->method('findActiveJobCoveringDate')
            ->with(
                self::COMPANY_ID,
                MarketplaceType::OZON,
                self::callback(static fn (\DateTimeImmutable $d): bool => self::REPORT_DATE === $d->format('Y-m-d')),
            )
            ->willReturn($job);
        $jobRepo->expects(self::once())->method('findFresh')->with($job->getId())->willReturn($job);
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::never())->method('markFailed');

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    public function testNoActiveJobForDateSkipsFinalization(): void
    {
        // Документ обработался, но активного job'а, покрывающего его дату, нет
        // (загружен CLI-командой вне orchestrator-flow). Handler не должен
        // падать и не должен пытаться финализировать / mark'ать.
        $rawDoc = $this->newDraftDocument();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $this->newProcessedDocument());
        $rawRepo->expects(self::never())->method('markFailedWithReason');
        $rawRepo->expects(self::never())->method('countByCompanyMarketplaceAndDateRange');
        $rawRepo->expects(self::never())->method('countTerminalByCompanyMarketplaceAndDateRange');

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->expects(self::once())
            ->method('findActiveJobCoveringDate')
            ->willReturn(null);
        $jobRepo->expects(self::never())->method('findFresh');
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::never())->method('markFailed');

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    public function testExceptionMarksDocumentFailedFinalizesAndRethrows(): void
    {
        // Error-ветка: Action бросил исключение. Handler должен
        //  1) атомарно пометить документ FAILED с причиной,
        //  2) триггерить финализацию (markFailed возможен, если это был
        //     последний документ и все chunks закрыты),
        //  3) rethrow'нуть исключение для Messenger retry / failed-queue.
        $rawDoc = $this->newDraftDocument();
        $freshJob = AdLoadJobBuilder::aJob()
            ->asRunning()
            ->withChunksTotal(5)
            ->withChunksCompleted(5) // все chunks закрыты — это последний документ
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        // Pre-read в handler'е (до Action); после Throwable повторного read нет.
        $rawRepo->expects(self::once())
            ->method('findByIdAndCompany')
            ->with(self::RAW_DOC_ID, self::COMPANY_ID)
            ->willReturn($rawDoc);
        $rawRepo->expects(self::once())
            ->method('markFailedWithReason')
            ->with(
                self::RAW_DOC_ID,
                self::COMPANY_ID,
                self::callback(static fn (string $r): bool => str_contains($r, 'parser failure')),
            )
            ->willReturn(1);
        $rawRepo->expects(self::once())
            ->method('countByCompanyMarketplaceAndDateRange')
            ->willReturn(10);
        $rawRepo->expects(self::once())
            ->method('countTerminalByCompanyMarketplaceAndDateRange')
            ->willReturn(['processed' => 9, 'failed' => 1]);

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->method('__invoke')->willThrowException(new \RuntimeException('parser failure'));

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->expects(self::once())->method('findActiveJobCoveringDate')->willReturn($freshJob);
        $jobRepo->expects(self::once())->method('findFresh')->willReturn($freshJob);
        // Есть failed документ → markFailed (partial failure), НЕ markCompleted.
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(
                $freshJob->getId(),
                self::COMPANY_ID,
                self::callback(static fn (string $r): bool => str_contains($r, '1/10') && str_contains($r, 'Partial failure')),
            )
            ->willReturn(1);

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('parser failure');
        $handler($this->newMessage());
    }

    public function testPartialDraftAfterActionMarksDocumentFailedAndReturnsSilently(): void
    {
        // Action не бросил исключение, но оставил документ в DRAFT (частичный
        // успех в ProcessAdRawDocumentAction: markAsProcessed вызывается только
        // когда !hasErrors). Handler помечает документ FAILED и идёт на
        // финализацию, но НЕ throw'ит — Action транзакцию уже закоммитил.
        $rawDoc = $this->newDraftDocument();
        $stillDraftAfter = $this->newDraftDocument();
        $job = AdLoadJobBuilder::aJob()
            ->asRunning()
            ->withChunksTotal(5)
            ->withChunksCompleted(1)
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $stillDraftAfter);
        $rawRepo->expects(self::once())
            ->method('markFailedWithReason')
            ->with(
                self::RAW_DOC_ID,
                self::COMPANY_ID,
                self::callback(static fn (string $r): bool => str_contains($r, 'Partial processing')),
            )
            ->willReturn(1);

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->expects(self::once())->method('findActiveJobCoveringDate')->willReturn($job);
        $jobRepo->expects(self::once())->method('findFresh')->willReturn($job);
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::never())->method('markFailed');

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        // НЕ ожидаем throw — partial success в Action не бросает.
        $handler($this->newMessage());
    }

    public function testAlreadyProcessedExceptionDoesNotMarkOrFinalize(): void
    {
        // Специфическая гонка: между pre-check в handler'е и вызовом Action
        // другой воркер успел обработать/завалить документ → Action бросает
        // AdRawDocumentAlreadyProcessedException. Этот воркер НЕ должен
        // переписывать markFailed — статус уже терминален — и не должен
        // финализировать: другой воркер уже это сделал.
        $rawDoc = $this->newDraftDocument();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->expects(self::once())
            ->method('findByIdAndCompany')
            ->willReturn($rawDoc);
        $rawRepo->expects(self::never())->method('markFailedWithReason');
        $rawRepo->expects(self::never())->method('countByCompanyMarketplaceAndDateRange');
        $rawRepo->expects(self::never())->method('countTerminalByCompanyMarketplaceAndDateRange');

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->method('__invoke')->willThrowException(new AdRawDocumentAlreadyProcessedException('already terminal'));

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->expects(self::never())->method('findActiveJobCoveringDate');
        $jobRepo->expects(self::never())->method('findFresh');
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::never())->method('markFailed');

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    public function testRetryAfterTerminalStatusIsIdempotent(): void
    {
        // Handler pre-check: документ уже в PROCESSED/FAILED → silent return.
        // Эмулируем retry Messenger'а после предыдущей неудачной обработки,
        // когда markFailedWithReason уже перевёл статус в FAILED.
        $failedDoc = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable(self::REPORT_DATE))
            ->asFailed('previous attempt')
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $rawRepo->expects(self::once())
            ->method('findByIdAndCompany')
            ->willReturn($failedDoc);
        $rawRepo->expects(self::never())->method('markFailedWithReason');

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::never())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->expects(self::never())->method('findActiveJobCoveringDate');
        $jobRepo->expects(self::never())->method('findFresh');

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    public function testJobFinalizedAsCompletedWhenAllTerminalWithoutFailures(): void
    {
        $rawDoc = $this->newDraftDocument();
        $freshJob = AdLoadJobBuilder::aJob()
            ->asRunning()
            ->withChunksTotal(5)
            ->withChunksCompleted(5)
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $this->newProcessedDocument());
        $rawRepo->expects(self::once())
            ->method('countByCompanyMarketplaceAndDateRange')
            ->willReturn(10);
        $rawRepo->expects(self::once())
            ->method('countTerminalByCompanyMarketplaceAndDateRange')
            ->willReturn(['processed' => 10, 'failed' => 0]);

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->method('findActiveJobCoveringDate')->willReturn($freshJob);
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
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $this->newProcessedDocument());
        $rawRepo->expects(self::once())
            ->method('countByCompanyMarketplaceAndDateRange')
            ->willReturn(10);
        $rawRepo->expects(self::once())
            ->method('countTerminalByCompanyMarketplaceAndDateRange')
            ->willReturn(['processed' => 8, 'failed' => 2]);

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->method('findActiveJobCoveringDate')->willReturn($freshJob);
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
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $this->newProcessedDocument());
        // COUNT и terminal-COUNT не запрашиваем — ранний return по chunks guard'у.
        $rawRepo->expects(self::never())->method('countByCompanyMarketplaceAndDateRange');
        $rawRepo->expects(self::never())->method('countTerminalByCompanyMarketplaceAndDateRange');

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->method('findActiveJobCoveringDate')->willReturn($job);
        $jobRepo->method('findFresh')->willReturn($job);
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::never())->method('markFailed');

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    public function testJobNotFinalizedWhenDocumentsIncomplete(): void
    {
        $rawDoc = $this->newDraftDocument();
        // chunks done, processed+failed=9 < total=10 → ещё не все документы
        // дошли до терминального состояния.
        $job = AdLoadJobBuilder::aJob()
            ->asRunning()
            ->withChunksTotal(5)
            ->withChunksCompleted(5)
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $this->newProcessedDocument());
        $rawRepo->expects(self::once())
            ->method('countByCompanyMarketplaceAndDateRange')
            ->willReturn(10);
        $rawRepo->expects(self::once())
            ->method('countTerminalByCompanyMarketplaceAndDateRange')
            ->willReturn(['processed' => 8, 'failed' => 1]); // 9 < 10

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->method('findActiveJobCoveringDate')->willReturn($job);
        $jobRepo->method('findFresh')->willReturn($job);
        $jobRepo->expects(self::never())->method('markCompleted');
        $jobRepo->expects(self::never())->method('markFailed');

        $handler = $this->createHandler($rawRepo, $action, $jobRepo);
        $handler($this->newMessage());
    }

    public function testFinalizationRaceDoesNotDoubleMark(): void
    {
        // Два воркера обрабатывают два последних документа параллельно. Оба
        // доходят до tryFinalizeJob. findFresh второго уже возвращает COMPLETED
        // job — ранний return по status guard'у, markCompleted не зовётся.
        $rawDoc = $this->newDraftDocument();

        $runningJob = AdLoadJobBuilder::aJob()
            ->asRunning()
            ->withChunksTotal(5)
            ->withChunksCompleted(5)
            ->build();
        $completedJob = AdLoadJobBuilder::aJob()
            ->asCompleted()
            ->withChunksTotal(5)
            ->withChunksCompleted(5)
            ->build();

        $rawRepo = $this->createMock(AdRawDocumentRepository::class);
        $this->wireRawRepoPrePost($rawRepo, $rawDoc, $this->newProcessedDocument());
        // При COMPLETED-статусе ранний return → COUNT не запрашивается.
        $rawRepo->expects(self::never())->method('countByCompanyMarketplaceAndDateRange');
        $rawRepo->expects(self::never())->method('countTerminalByCompanyMarketplaceAndDateRange');

        $action = $this->createMock(ProcessAdRawDocumentAction::class);
        $action->expects(self::once())->method('__invoke');

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->method('findActiveJobCoveringDate')->willReturn($runningJob);
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
