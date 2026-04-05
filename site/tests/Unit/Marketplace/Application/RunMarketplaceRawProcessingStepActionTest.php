<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\Command\RunMarketplaceRawProcessingStepCommand;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorInterface;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistryInterface;
use App\Marketplace\Application\RunMarketplaceRawProcessingStepAction;
use App\Marketplace\Domain\Service\ResolveMarketplaceRawProcessingProfile;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Repository\MarketplaceRawProcessingRunRepository;
use App\Marketplace\Repository\MarketplaceRawProcessingStepRunRepository;
use App\Tests\Builders\Marketplace\MarketplaceRawProcessingRunBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawProcessingStepRunBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RunMarketplaceRawProcessingStepActionTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const RUN_ID     = '33333333-3333-3333-3333-333333333333';
    private const STEP_ID    = '44444444-4444-4444-4444-444444444444';
    private const RAW_DOC_ID = '22222222-2222-2222-2222-222222222222';

    public function testHappyPathPendingStepBecomesCompleted(): void
    {
        $run     = MarketplaceRawProcessingRunBuilder::aRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withRawDocumentId(self::RAW_DOC_ID)
            ->build();
        $stepRun = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId($run->getId())
            ->forStep(PipelineStep::SALES)
            ->build();

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor->method('process')->with(self::COMPANY_ID, self::RAW_DOC_ID)->willReturn(42);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeast(2))->method('flush'); // once for RUNNING, once for COMPLETED

        $action = $this->buildAction(
            run: $run,
            stepRun: $stepRun,
            processorReturn: 42,
            em: $em,
        );

        $processed = $action(new RunMarketplaceRawProcessingStepCommand(
            self::COMPANY_ID,
            $run->getId(),
            $stepRun->getId(),
        ));

        self::assertSame(42, $processed);
        self::assertSame(PipelineStatus::COMPLETED, $stepRun->getStatus());
        self::assertSame(42, $stepRun->getProcessedCount());
    }

    public function testTerminalStepIsSkippedIdempotently(): void
    {
        $run     = MarketplaceRawProcessingRunBuilder::aRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withRawDocumentId(self::RAW_DOC_ID)
            ->build();
        $stepRun = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId($run->getId())
            ->forStep(PipelineStep::SALES)
            ->build();

        // put step into terminal state
        $stepRun->markRunning();
        $stepRun->markCompleted(10, 0, 0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $action = $this->buildAction(run: $run, stepRun: $stepRun, processorReturn: 0, em: $em);

        $result = $action(new RunMarketplaceRawProcessingStepCommand(
            self::COMPANY_ID,
            $run->getId(),
            $stepRun->getId(),
        ));

        self::assertSame(0, $result);
    }

    public function testRunNotFoundThrows(): void
    {
        $runRepo = $this->createMock(MarketplaceRawProcessingRunRepository::class);
        $runRepo->method('findByIdAndCompany')->willReturn(null);

        $action = new RunMarketplaceRawProcessingStepAction(
            $runRepo,
            $this->createMock(MarketplaceRawProcessingStepRunRepository::class),
            $this->createMock(MarketplaceRawDocumentRepository::class),
            $this->createMock(MarketplaceRawProcessorRegistryInterface::class),
            new ResolveMarketplaceRawProcessingProfile(),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );

        $this->expectException(\DomainException::class);

        $action(new RunMarketplaceRawProcessingStepCommand(self::COMPANY_ID, self::RUN_ID, self::STEP_ID));
    }

    public function testStepRunNotFoundThrows(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->withCompanyId(self::COMPANY_ID)->build();

        $runRepo = $this->createMock(MarketplaceRawProcessingRunRepository::class);
        $runRepo->method('findByIdAndCompany')->willReturn($run);

        $stepRepo = $this->createMock(MarketplaceRawProcessingStepRunRepository::class);
        $stepRepo->method('findByIdAndCompany')->willReturn(null);

        $action = new RunMarketplaceRawProcessingStepAction(
            $runRepo,
            $stepRepo,
            $this->createMock(MarketplaceRawDocumentRepository::class),
            $this->createMock(MarketplaceRawProcessorRegistryInterface::class),
            new ResolveMarketplaceRawProcessingProfile(),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );

        $this->expectException(\DomainException::class);

        $action(new RunMarketplaceRawProcessingStepCommand(self::COMPANY_ID, $run->getId(), self::STEP_ID));
    }

    public function testStepRunFromDifferentRunThrows(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->withCompanyId(self::COMPANY_ID)->build();
        // step belonging to a different run
        $stepRun = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId('ffffffff-ffff-ffff-ffff-ffffffffffff')
            ->build();

        $runRepo = $this->createMock(MarketplaceRawProcessingRunRepository::class);
        $runRepo->method('findByIdAndCompany')->willReturn($run);

        $stepRepo = $this->createMock(MarketplaceRawProcessingStepRunRepository::class);
        $stepRepo->method('findByIdAndCompany')->willReturn($stepRun);

        $action = new RunMarketplaceRawProcessingStepAction(
            $runRepo,
            $stepRepo,
            $this->createMock(MarketplaceRawDocumentRepository::class),
            $this->createMock(MarketplaceRawProcessorRegistryInterface::class),
            new ResolveMarketplaceRawProcessingProfile(),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );

        $this->expectException(\DomainException::class);

        $action(new RunMarketplaceRawProcessingStepCommand(self::COMPANY_ID, $run->getId(), $stepRun->getId()));
    }

    public function testDomainExceptionFromProcessorMarksStepFailed(): void
    {
        $run     = MarketplaceRawProcessingRunBuilder::aRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withRawDocumentId(self::RAW_DOC_ID)
            ->build();
        $stepRun = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId($run->getId())
            ->forStep(PipelineStep::SALES)
            ->build();

        $action = $this->buildAction(
            run: $run,
            stepRun: $stepRun,
            processorException: new \DomainException('business error'),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('business error');

        try {
            $action(new RunMarketplaceRawProcessingStepCommand(
                self::COMPANY_ID,
                $run->getId(),
                $stepRun->getId(),
            ));
        } finally {
            self::assertSame(PipelineStatus::FAILED, $stepRun->getStatus());
            self::assertSame('business error', $stepRun->getErrorMessage());
        }
    }

    public function testInfraErrorKeepsStepRunning(): void
    {
        $run     = MarketplaceRawProcessingRunBuilder::aRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withRawDocumentId(self::RAW_DOC_ID)
            ->build();
        $stepRun = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId($run->getId())
            ->forStep(PipelineStep::SALES)
            ->build();

        $action = $this->buildAction(
            run: $run,
            stepRun: $stepRun,
            processorException: new \RuntimeException('db connection lost'),
        );

        $this->expectException(\RuntimeException::class);

        try {
            $action(new RunMarketplaceRawProcessingStepCommand(
                self::COMPANY_ID,
                $run->getId(),
                $stepRun->getId(),
            ));
        } finally {
            // step must remain RUNNING so Messenger can retry
            self::assertSame(PipelineStatus::RUNNING, $stepRun->getStatus());
        }
    }

    // -------------------------------------------------------------------------

    private function buildAction(
        \App\Marketplace\Entity\MarketplaceRawProcessingRun $run,
        \App\Marketplace\Entity\MarketplaceRawProcessingStepRun $stepRun,
        int $processorReturn = 0,
        ?\Throwable $processorException = null,
        ?EntityManagerInterface $em = null,
    ): RunMarketplaceRawProcessingStepAction {
        $runRepo = $this->createMock(MarketplaceRawProcessingRunRepository::class);
        $runRepo->method('findByIdAndCompany')->willReturn($run);

        $stepRepo = $this->createMock(MarketplaceRawProcessingStepRunRepository::class);
        $stepRepo->method('findByIdAndCompany')->willReturn($stepRun);

        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn(self::COMPANY_ID);

        $doc = $this->createMock(MarketplaceRawDocument::class);
        $doc->method('getCompany')->willReturn($company);
        $doc->method('getDocumentType')->willReturn('sales_report');
        $doc->method('getMarketplace')->willReturn(MarketplaceType::WILDBERRIES);

        $docRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $docRepo->method('find')->willReturn($doc);

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        if ($processorException !== null) {
            $processor->method('process')->willThrowException($processorException);
        } else {
            $processor->method('process')->willReturn($processorReturn);
        }

        $registry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $registry->method('get')->willReturn($processor);

        return new RunMarketplaceRawProcessingStepAction(
            $runRepo,
            $stepRepo,
            $docRepo,
            $registry,
            new ResolveMarketplaceRawProcessingProfile(),
            $em ?? $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );
    }
}
