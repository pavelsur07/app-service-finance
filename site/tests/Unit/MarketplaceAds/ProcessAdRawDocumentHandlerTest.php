<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\ProcessAdRawDocumentAction;
use App\MarketplaceAds\Application\Service\AdLoadJobFinalizer;
use App\MarketplaceAds\Exception\AdRawDocumentAlreadyProcessedException;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\MessageHandler\ProcessAdRawDocumentHandler;
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

// Bootstrap pins BypassFinals to an allowlist; extend it so the action and finalizer
// under test can be doubled without touching the global bootstrap configuration.
BypassFinals::allowPaths([
    '*/src/MarketplaceAds/Application/ProcessAdRawDocumentAction.php',
    '*/src/MarketplaceAds/Application/Service/AdLoadJobFinalizer.php',
]);

/**
 * Unit-тесты {@see ProcessAdRawDocumentHandler}: per-document FAILED + делегирование финализации.
 *
 * Покрываемые инварианты handler'а:
 *  - PROCESSED-документ → AdLoadJobFinalizer::tryFinalize(jobId) вызывается.
 *  - DRAFT после Action → markFailedWithReason + return без throw.
 *  - Исключение из Action → markFailedWithReason + tryFinalize + rethrow.
 *  - AdRawDocumentAlreadyProcessedException → swallow, finalizer не вызывается.
 *  - Нет активного job на дату → finalizer не вызывается.
 *  - Secondary exception после Action-failure не маскирует оригинал.
 *
 * Сама логика финализации (chunksTotal, COUNT-и, markCompleted/markFailed) живёт
 * в {@see AdLoadJobFinalizer} и покрыта {@see AdLoadJobFinalizerTest}.
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
    /** @var AdLoadJobFinalizer&MockObject */
    private AdLoadJobFinalizer $finalizer;
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $entityManager;
    private AppLogger $logger;
    private ProcessAdRawDocumentHandler $handler;

    protected function setUp(): void
    {
        $this->action = $this->createMock(ProcessAdRawDocumentAction::class);
        $this->rawDocRepo = $this->createMock(AdRawDocumentRepositoryInterface::class);
        $this->jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $this->finalizer = $this->createMock(AdLoadJobFinalizer::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = new AppLogger(new NullLogger(), $this->createMock(HubInterface::class));

        $this->handler = new ProcessAdRawDocumentHandler(
            $this->action,
            $this->rawDocRepo,
            $this->jobRepo,
            $this->finalizer,
            $this->entityManager,
            $this->logger,
            new NullLogger(),
        );
    }

    public function testProcessedDocumentTriggersFinalizer(): void
    {
        $document = $this->buildProcessedDocument();
        $job = $this->buildRunningJob();

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

        $this->finalizer->expects(self::once())
            ->method('tryFinalize')
            ->with(self::JOB_ID, self::COMPANY_ID);

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

        // Job не найден на дату → finalizer не вызывается.
        $this->jobRepo->expects(self::once())
            ->method('findActiveJobCoveringDate')
            ->willReturn(null);
        $this->finalizer->expects(self::never())->method('tryFinalize');

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
        $this->finalizer->expects(self::never())->method('tryFinalize');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        ($this->handler)(new ProcessAdRawDocumentMessage(self::COMPANY_ID, self::DOCUMENT_ID));
    }

    public function testSecondaryExceptionFromFinalizeDoesNotMaskOriginalOnActionFailure(): void
    {
        // Гонка после wrapInTransaction: Doctrine закрыл EntityManager, и ORM-запросы
        // внутри tryFinalizeJobForDocument кидают "EntityManager is closed". Handler
        // обязан поглотить secondary и пробросить ОРИГИНАЛЬНОЕ исключение, чтобы
        // Messenger failed-queue увидел реальную причину, а не маску.
        $original = new \RuntimeException('db constraint violation');

        $this->action->expects(self::once())
            ->method('__invoke')
            ->willThrowException($original);

        $this->entityManager->expects(self::never())->method('flush');

        $this->rawDocRepo->expects(self::once())
            ->method('markFailedWithReason')
            ->willReturn(1);

        // tryFinalizeJobForDocument → findByIdAndCompany бросает secondary.
        $this->rawDocRepo->expects(self::once())
            ->method('findByIdAndCompany')
            ->willThrowException(new \RuntimeException('EntityManager is closed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db constraint violation');

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
        $this->finalizer->expects(self::never())->method('tryFinalize');

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
        $this->finalizer->expects(self::never())->method('tryFinalize');

        ($this->handler)(new ProcessAdRawDocumentMessage(self::COMPANY_ID, self::DOCUMENT_ID));
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

    private function buildRunningJob(): object
    {
        return AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withDateRange(
                new \DateTimeImmutable('2026-03-01'),
                new \DateTimeImmutable('2026-03-10'),
            )
            ->withChunksTotal(1)
            ->asRunning()
            ->build();
    }
}
