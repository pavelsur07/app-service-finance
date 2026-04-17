<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
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
use Psr\Log\NullLogger;

// Bootstrap pins BypassFinals to an allowlist; extend it so the action under test
// can be doubled without touching the global bootstrap configuration.
BypassFinals::allowPaths([
    '*/src/Marketplace/Application/ProcessMarketplaceRawDocumentAction.php',
]);

final class ProcessRawDocumentStepMessageHandlerTest extends TestCase
{
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
        );

        $message = new ProcessRawDocumentStepMessage(
            rawDocumentId: $doc->getId(),
            step:          PipelineStep::SALES->value,
            companyId:     $company->getId(),
        );

        $this->expectExceptionObject($primaryException);
        $handler($message);
    }
}
