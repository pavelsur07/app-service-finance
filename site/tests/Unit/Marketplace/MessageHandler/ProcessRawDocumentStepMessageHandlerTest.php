<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\DTO\ProcessRawDocumentResult;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Application\Service\WbFinancialReportSyncStatusUpdaterInterface;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Message\ProcessRawDocumentStepMessage;
use App\Marketplace\MessageHandler\ProcessRawDocumentStepMessageHandler;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use DG\BypassFinals;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

// Bootstrap pins BypassFinals to an allowlist; extend it so the action under test
// can be doubled without touching the global bootstrap configuration.
BypassFinals::allowPaths([
    '*/src/Marketplace/Application/ProcessMarketplaceRawDocumentAction.php',
]);

final class ProcessRawDocumentStepMessageHandlerTest extends TestCase
{
    public function testLegacySerializedCostsMessageDoesNotPretendForceRefreshWasRequested(): void
    {
        $user    = UserBuilder::aUser()->withIndex(6)->build();
        $company = CompanyBuilder::aCompany()->withIndex(6)->withOwner($user)->build();
        $doc     = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->build();

        $repo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repo->method('find')->with($doc->getId())->willReturn($doc);

        $captured = null;
        $processAction = $this->createMock(ProcessMarketplaceRawDocumentAction::class);
        $processAction->expects(self::once())
            ->method('__invoke')
            ->willReturnCallback(static function (ProcessMarketplaceRawDocumentCommand $command) use (&$captured): ProcessRawDocumentResult {
                $captured = $command;

                return new ProcessRawDocumentResult(1);
            });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $updater = $this->createMock(WbFinancialReportSyncStatusUpdaterInterface::class);
        $updater->expects(self::once())->method('syncByRawPipelineResult')->with($doc);

        $handler = new ProcessRawDocumentStepMessageHandler(
            $repo,
            $processAction,
            $em,
            $this->createMock(ManagerRegistry::class),
            new NullLogger(),
            $updater,
        );

        $message = $this->legacySerializedStepMessage($doc->getId(), PipelineStep::COSTS->value, $company->getId());
        self::assertFalse($message->shouldForceRefresh());

        $handler($message);

        self::assertInstanceOf(ProcessMarketplaceRawDocumentCommand::class, $captured);
        self::assertSame(PipelineStep::COSTS->value, $captured->kind);
        self::assertFalse($captured->forceReprocess);
    }

    public function testForceRefreshForcesEveryPipelineStepToReprocessGeneratedRows(): void
    {
        $user    = UserBuilder::aUser()->withIndex(5)->build();
        $company = CompanyBuilder::aCompany()->withIndex(5)->withOwner($user)->build();
        $doc     = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->build();

        $repo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repo->method('find')->with($doc->getId())->willReturn($doc);

        $forceByStep = [];
        $processAction = $this->createMock(ProcessMarketplaceRawDocumentAction::class);
        $processAction->expects(self::exactly(count(PipelineStep::cases())))
            ->method('__invoke')
            ->willReturnCallback(static function (ProcessMarketplaceRawDocumentCommand $command) use (&$forceByStep): ProcessRawDocumentResult {
                $forceByStep[$command->kind] = $command->forceReprocess;

                return new ProcessRawDocumentResult(1);
            });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(count(PipelineStep::cases())))->method('flush');

        $updater = $this->createMock(WbFinancialReportSyncStatusUpdaterInterface::class);
        $updater->expects(self::exactly(count(PipelineStep::cases())))
            ->method('syncByRawPipelineResult')
            ->with($doc);

        $handler = new ProcessRawDocumentStepMessageHandler(
            $repo,
            $processAction,
            $em,
            $this->createMock(ManagerRegistry::class),
            new NullLogger(),
            $updater,
        );

        foreach (PipelineStep::cases() as $step) {
            $handler(new ProcessRawDocumentStepMessage(
                rawDocumentId: $doc->getId(),
                step: $step->value,
                companyId: $company->getId(),
                forceRefresh: true,
            ));
        }

        self::assertSame([
            PipelineStep::SALES->value => true,
            PipelineStep::RETURNS->value => true,
            PipelineStep::COSTS->value => true,
        ], $forceByStep);
    }

    /**
     * Regression: when the inner pipeline throws AND closes the EM, the handler must
     * reset the manager, re-fetch the document, mark the step failed, flush on the
     * fresh EM, and rethrow the ORIGINAL exception (not the secondary one).
     *
     * Previously the catch-block called repository->find() + em->flush() directly on
     * the closed EM — both failed, the secondary exception masked the root cause,
     * and the document was stuck in "processing" forever.
     */
    public function testHandlerRecordsFailureWhenEmWasClosedByPrimaryException(): void
    {
        $user    = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $doc     = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->build();

        $primaryException = new \RuntimeException('Deadlock detected — EM closed downstream');

        // Initial repository inside handler returns the doc for the IDOR/company check.
        $initialRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $initialRepo->method('find')->with($doc->getId())->willReturn($doc);

        // Inner action throws and leaves the EM closed.
        $processAction = $this->createMock(ProcessMarketplaceRawDocumentAction::class);
        $processAction->method('__invoke')->willThrowException($primaryException);

        // The "old" EM is closed after the action throws.
        $closedEm = $this->createMock(EntityManagerInterface::class);
        $closedEm->method('isOpen')->willReturn(false);
        // flush() must NOT be called on the closed EM — handler must go through the registry.
        $closedEm->expects(self::never())->method('flush');

        // The "fresh" EM returned after resetManager() handles the markStepFailed() flush.
        $freshEm        = $this->createMock(EntityManagerInterface::class);
        $freshRepo      = $this->createMock(EntityRepository::class);
        $freshRepo->expects(self::once())
            ->method('find')
            ->with($doc->getId())
            ->willReturn($doc);
        $freshEm->method('getRepository')
            ->with(MarketplaceRawDocument::class)
            ->willReturn($freshRepo);
        $freshEm->expects(self::once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');
        $registry->method('getManager')->willReturn($freshEm);

        $handler = new ProcessRawDocumentStepMessageHandler(
            $initialRepo,
            $processAction,
            $closedEm,
            $registry,
            new NullLogger(),
            $this->createMock(WbFinancialReportSyncStatusUpdaterInterface::class),
        );

        $message = new ProcessRawDocumentStepMessage(
            rawDocumentId: $doc->getId(),
            step:          PipelineStep::COSTS->value,
            companyId:     $company->getId(),
        );

        try {
            $handler($message);
            self::fail('Expected primary exception to be rethrown.');
        } catch (\Throwable $actual) {
            self::assertSame(
                $primaryException,
                $actual,
                'Primary exception must propagate — secondary failures must not mask it.',
            );
        }

        self::assertContains(
            PipelineStep::COSTS->value,
            $doc->getFailedSteps(),
            'Document must be marked failed on the step that threw.',
        );
    }

    /**
     * Regression: when the EM is still open after the action throws, the handler
     * should NOT reset the manager — it should flush on the original EM directly.
     */
    public function testHandlerFlushesOnOriginalEmWhenStillOpen(): void
    {
        $user    = UserBuilder::aUser()->withIndex(2)->build();
        $company = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->build();
        $doc     = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->build();

        $primaryException = new \RuntimeException('Recoverable business error');

        $initialRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $initialRepo->method('find')->with($doc->getId())->willReturn($doc);

        $processAction = $this->createMock(ProcessMarketplaceRawDocumentAction::class);
        $processAction->method('__invoke')->willThrowException($primaryException);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->expects(self::once())->method('flush');
        $freshRepo = $this->createMock(EntityRepository::class);
        $freshRepo->expects(self::once())
            ->method('find')
            ->with($doc->getId())
            ->willReturn($doc);
        $em->method('getRepository')
            ->with(MarketplaceRawDocument::class)
            ->willReturn($freshRepo);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('resetManager');
        $registry->method('getManager')->willReturn($em);

        $handler = new ProcessRawDocumentStepMessageHandler(
            $initialRepo,
            $processAction,
            $em,
            $registry,
            new NullLogger(),
            $this->createMock(WbFinancialReportSyncStatusUpdaterInterface::class),
        );

        $message = new ProcessRawDocumentStepMessage(
            rawDocumentId: $doc->getId(),
            step:          PipelineStep::SALES->value,
            companyId:     $company->getId(),
        );

        $this->expectExceptionObject($primaryException);
        $handler($message);
    }

    /**
     * Регрессия: частичная переобработка (сохранены linked rows закрытого документа)
     * раньше писалась в failed_steps и делала день недостижимо красным.
     * Теперь это успех шага с warning-логом.
     */
    public function testPartialReprocessMarksStepSucceededAndLogsPreservedRows(): void
    {
        $user = UserBuilder::aUser()->withIndex(7)->build();
        $company = CompanyBuilder::aCompany()->withIndex(7)->withOwner($user)->build();
        $doc = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->build();

        $repo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repo->method('find')->with($doc->getId())->willReturn($doc);

        $processAction = $this->createMock(ProcessMarketplaceRawDocumentAction::class);
        $processAction->method('__invoke')->willReturn(new ProcessRawDocumentResult(42, 13));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $updater = $this->createMock(WbFinancialReportSyncStatusUpdaterInterface::class);
        $updater->expects(self::once())->method('syncByRawPipelineResult')->with($doc, null, [
            'sync_status_id' => '00000000-0000-0000-0000-000000000710',
            'company_id' => $company->getId(),
            'connection_id' => '00000000-0000-0000-0000-000000000711',
            'marketplace' => 'wildberries',
            'report_type' => 'sales_report',
            'mode' => 'refresh_14d',
            'business_date' => '2026-07-28',
            'raw_document_id' => $doc->getId(),
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'WB raw document step partially reprocessed; linked rows were preserved',
            [
                'company_id' => $company->getId(),
                'raw_document_id' => $doc->getId(),
                'step' => PipelineStep::SALES->value,
                'linked_rows_preserved' => 13,
            ],
        );

        $handler = new ProcessRawDocumentStepMessageHandler(
            $repo,
            $processAction,
            $em,
            $this->createMock(ManagerRegistry::class),
            $logger,
            $updater,
        );

        $handler(new ProcessRawDocumentStepMessage(
            rawDocumentId: $doc->getId(),
            step: PipelineStep::SALES->value,
            companyId: $company->getId(),
            syncStatusId: '00000000-0000-0000-0000-000000000710',
            connectionId: '00000000-0000-0000-0000-000000000711',
            marketplace: 'wildberries',
            reportType: 'sales_report',
            mode: 'refresh_14d',
            businessDate: '2026-07-28',
            forceRefresh: true,
        ));

        self::assertContains(PipelineStep::SALES->value, $doc->getSucceededSteps());
        self::assertNotContains(PipelineStep::SALES->value, $doc->getFailedSteps());
    }

    public function testHandlerRethrowsFailureWhenSyncStatusCannotBeUpdated(): void
    {
        $user = UserBuilder::aUser()->withIndex(8)->build();
        $company = CompanyBuilder::aCompany()->withIndex(8)->withOwner($user)->build();
        $doc = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->build();
        $failure = new \RuntimeException('processor failed');

        $initialRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $initialRepo->method('find')->with($doc->getId())->willReturn($doc);

        $processAction = $this->createMock(ProcessMarketplaceRawDocumentAction::class);
        $processAction->method('__invoke')->willThrowException($failure);

        $freshRepo = $this->createMock(EntityRepository::class);
        $freshRepo->method('find')->with($doc->getId())->willReturn($doc);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('getRepository')->with(MarketplaceRawDocument::class)->willReturn($freshRepo);
        $em->expects(self::once())->method('flush');

        $updater = $this->createMock(WbFinancialReportSyncStatusUpdaterInterface::class);
        $updater->method('syncByRawPipelineResult')->willThrowException(new \RuntimeException('status unavailable'));

        $handler = new ProcessRawDocumentStepMessageHandler(
            $initialRepo,
            $processAction,
            $em,
            $this->createMock(ManagerRegistry::class),
            $this->createMock(LoggerInterface::class),
            $updater,
        );

        $this->expectExceptionObject($failure);
        $handler(new ProcessRawDocumentStepMessage(
            $doc->getId(),
            PipelineStep::SALES->value,
            $company->getId(),
        ));
    }

    public function testHandlerSyncsWbStatusOnSuccessfulPipelineCompletion(): void
    {
        $user    = UserBuilder::aUser()->withIndex(3)->build();
        $company = CompanyBuilder::aCompany()->withIndex(3)->withOwner($user)->build();
        $doc     = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->build();

        $repo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repo->method('find')->with($doc->getId())->willReturn($doc);

        $processAction = $this->createMock(ProcessMarketplaceRawDocumentAction::class);
        $processAction->method('__invoke')->willReturn(new ProcessRawDocumentResult(10));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(count(PipelineStep::cases())))->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);

        $updater = $this->createMock(WbFinancialReportSyncStatusUpdaterInterface::class);
        $updater->expects(self::exactly(count(PipelineStep::cases())))
            ->method('syncByRawPipelineResult')
            ->with($doc);

        $handler = new ProcessRawDocumentStepMessageHandler(
            $repo,
            $processAction,
            $em,
            $registry,
            new NullLogger(),
            $updater,
        );

        foreach (PipelineStep::cases() as $step) {
            $handler(new ProcessRawDocumentStepMessage($doc->getId(), $step->value, $company->getId()));
        }
    }


    public function testHandlerIgnoresUpdaterExceptionOnSuccessPath(): void
    {
        $user    = UserBuilder::aUser()->withIndex(4)->build();
        $company = CompanyBuilder::aCompany()->withIndex(4)->withOwner($user)->build();
        $doc     = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->build();

        $repo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repo->method('find')->with($doc->getId())->willReturn($doc);

        $processAction = $this->createMock(ProcessMarketplaceRawDocumentAction::class);
        $processAction->method('__invoke')->willReturn(new ProcessRawDocumentResult(1));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);

        $updater = $this->createMock(WbFinancialReportSyncStatusUpdaterInterface::class);
        $updater->method('syncByRawPipelineResult')->willThrowException(new \RuntimeException('updater failed'));

        $handler = new ProcessRawDocumentStepMessageHandler(
            $repo,
            $processAction,
            $em,
            $registry,
            new NullLogger(),
            $updater,
        );

        $handler(new ProcessRawDocumentStepMessage($doc->getId(), PipelineStep::SALES->value, $company->getId()));

        self::assertContains(PipelineStep::SALES->value, $doc->getSucceededSteps());
        self::assertNotContains(PipelineStep::SALES->value, $doc->getFailedSteps());
    }

    private function legacySerializedStepMessage(string $rawDocumentId, string $step, string $companyId): ProcessRawDocumentStepMessage
    {
        $class = ProcessRawDocumentStepMessage::class;
        $payload = sprintf(
            'O:%d:"%s":9:{s:13:"rawDocumentId";s:%d:"%s";s:4:"step";s:%d:"%s";s:9:"companyId";s:%d:"%s";s:12:"syncStatusId";N;s:12:"connectionId";N;s:11:"marketplace";N;s:10:"reportType";N;s:4:"mode";N;s:12:"businessDate";N;}',
            strlen($class),
            $class,
            strlen($rawDocumentId),
            $rawDocumentId,
            strlen($step),
            $step,
            strlen($companyId),
            $companyId,
        );

        $message = unserialize($payload, ['allowed_classes' => [ProcessRawDocumentStepMessage::class]]);
        self::assertInstanceOf(ProcessRawDocumentStepMessage::class, $message);

        return $message;
    }
}
