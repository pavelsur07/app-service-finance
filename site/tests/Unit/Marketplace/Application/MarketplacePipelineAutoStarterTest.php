<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application;

use App\Marketplace\Application\Command\StartMarketplaceRawProcessingCommand;
use App\Marketplace\Application\Service\MarketplacePipelineAutoStarter;
use App\Marketplace\Application\StartMarketplaceRawProcessingAction;
use App\Marketplace\Enum\PipelineTrigger;
use App\Marketplace\Repository\MarketplaceRawProcessingRunRepository;
use App\Tests\Builders\Marketplace\MarketplaceRawProcessingRunBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MarketplacePipelineAutoStarterTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const RAW_DOC_ID = '22222222-2222-2222-2222-222222222222';

    public function testStartsPipelineWhenNoExistingRun(): void
    {
        $runRepo = $this->createMock(MarketplaceRawProcessingRunRepository::class);
        $runRepo->method('findLatestByRawDocument')->willReturn(null);

        $startAction = $this->createMock(StartMarketplaceRawProcessingAction::class);
        $startAction->expects($this->once())
            ->method('__invoke')
            ->with($this->callback(function (StartMarketplaceRawProcessingCommand $cmd): bool {
                return $cmd->companyId === self::COMPANY_ID
                    && $cmd->rawDocumentId === self::RAW_DOC_ID
                    && $cmd->trigger === PipelineTrigger::AUTO;
            }))
            ->willReturn('new-run-id');

        $starter = new MarketplacePipelineAutoStarter($startAction, $runRepo, new NullLogger());
        $starter->tryStart(self::COMPANY_ID, self::RAW_DOC_ID);
    }

    public function testStartsPipelineWhenPreviousRunIsTerminal(): void
    {
        $completedRun = MarketplaceRawProcessingRunBuilder::aRun()->withCompanyId(self::COMPANY_ID)->build();
        $completedRun->markCompleted();

        $runRepo = $this->createMock(MarketplaceRawProcessingRunRepository::class);
        $runRepo->method('findLatestByRawDocument')->willReturn($completedRun);

        $startAction = $this->createMock(StartMarketplaceRawProcessingAction::class);
        $startAction->expects($this->once())->method('__invoke')->willReturn('new-run-id');

        $starter = new MarketplacePipelineAutoStarter($startAction, $runRepo, new NullLogger());
        $starter->tryStart(self::COMPANY_ID, self::RAW_DOC_ID);
    }

    public function testSkipsPipelineWhenActiveRunExists(): void
    {
        $activeRun = MarketplaceRawProcessingRunBuilder::aRun()->withCompanyId(self::COMPANY_ID)->build();
        // run is RUNNING (non-terminal)

        $runRepo = $this->createMock(MarketplaceRawProcessingRunRepository::class);
        $runRepo->method('findLatestByRawDocument')->willReturn($activeRun);

        $startAction = $this->createMock(StartMarketplaceRawProcessingAction::class);
        $startAction->expects($this->never())->method('__invoke');

        $starter = new MarketplacePipelineAutoStarter($startAction, $runRepo, new NullLogger());
        $starter->tryStart(self::COMPANY_ID, self::RAW_DOC_ID);
    }

    public function testDoesNotPropagateExceptionsFromStartAction(): void
    {
        $runRepo = $this->createMock(MarketplaceRawProcessingRunRepository::class);
        $runRepo->method('findLatestByRawDocument')->willReturn(null);

        $startAction = $this->createMock(StartMarketplaceRawProcessingAction::class);
        $startAction->method('__invoke')->willThrowException(new \DomainException('document not found'));

        $starter = new MarketplacePipelineAutoStarter($startAction, $runRepo, new NullLogger());

        // must not throw
        $starter->tryStart(self::COMPANY_ID, self::RAW_DOC_ID);

        $this->addToAssertionCount(1); // confirm no exception
    }

    public function testDoesNotPropagateRuntimeExceptionFromStartAction(): void
    {
        $runRepo = $this->createMock(MarketplaceRawProcessingRunRepository::class);
        $runRepo->method('findLatestByRawDocument')->willReturn(null);

        $startAction = $this->createMock(StartMarketplaceRawProcessingAction::class);
        $startAction->method('__invoke')->willThrowException(new \RuntimeException('unexpected infra error'));

        $starter = new MarketplacePipelineAutoStarter($startAction, $runRepo, new NullLogger());

        // must not throw — best-effort
        $starter->tryStart(self::COMPANY_ID, self::RAW_DOC_ID);

        $this->addToAssertionCount(1);
    }
}
