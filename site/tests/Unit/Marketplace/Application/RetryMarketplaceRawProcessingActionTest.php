<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application;

use App\Marketplace\Application\Command\RetryMarketplaceRawProcessingCommand;
use App\Marketplace\Application\RetryMarketplaceRawProcessingAction;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Message\RunMarketplaceRawProcessingStepMessage;
use App\Marketplace\Repository\MarketplaceRawProcessingRunRepository;
use App\Marketplace\Repository\MarketplaceRawProcessingStepRunRepository;
use App\Tests\Builders\Marketplace\MarketplaceRawProcessingRunBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawProcessingStepRunBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RetryMarketplaceRawProcessingActionTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    public function testHappyPathResetsFailedStepsAndDispatches(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->withCompanyId(self::COMPANY_ID)->build();
        $run->markFailed('error');

        $step1 = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId($run->getId())
            ->build();
        $step1->markRunning();
        $step1->markFailed('step1 error', 0, 0, 0);

        $step2 = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId($run->getId())
            ->build();
        $step2->markRunning();
        $step2->markCompleted(5, 0, 0); // already completed — should not be reset

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatched) {
                $dispatched[] = $message;
                return new Envelope($message);
            });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $action = $this->buildAction($run, [$step1, $step2], $bus, $em);
        $action(new RetryMarketplaceRawProcessingCommand(self::COMPANY_ID, $run->getId()));

        // run reset to RUNNING
        self::assertSame(PipelineStatus::RUNNING, $run->getStatus());
        // only the failed step reset
        self::assertSame(PipelineStatus::PENDING, $step1->getStatus());
        // completed step untouched
        self::assertSame(PipelineStatus::COMPLETED, $step2->getStatus());
        // dispatch only for reset step
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(RunMarketplaceRawProcessingStepMessage::class, $dispatched[0]);
    }

    public function testRunNotFoundThrows(): void
    {
        $runRepo = $this->createMock(MarketplaceRawProcessingRunRepository::class);
        $runRepo->method('findByIdAndCompany')->willReturn(null);

        $action = new RetryMarketplaceRawProcessingAction(
            $runRepo,
            $this->createMock(MarketplaceRawProcessingStepRunRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MessageBusInterface::class),
        );

        $this->expectException(\DomainException::class);

        $action(new RetryMarketplaceRawProcessingCommand(self::COMPANY_ID, 'no-run'));
    }

    public function testNoFailedStepsThrows(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->withCompanyId(self::COMPANY_ID)->build();
        $run->markFailed('error');

        $step = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId($run->getId())
            ->build();
        $step->markRunning();
        $step->markCompleted(5, 0, 0);

        $action = $this->buildAction($run, [$step]);

        $this->expectException(\DomainException::class);

        $action(new RetryMarketplaceRawProcessingCommand(self::COMPANY_ID, $run->getId()));
    }

    public function testRetryingNonFailedRunThrows(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->withCompanyId(self::COMPANY_ID)->build();
        // run stays RUNNING (not FAILED)

        $step = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId($run->getId())
            ->build();
        $step->markRunning();
        $step->markFailed('step error');

        $action = $this->buildAction($run, [$step]);

        $this->expectException(\DomainException::class);

        $action(new RetryMarketplaceRawProcessingCommand(self::COMPANY_ID, $run->getId()));
    }

    // -------------------------------------------------------------------------

    private function buildAction(
        \App\Marketplace\Entity\MarketplaceRawProcessingRun $run,
        array $stepRuns,
        ?MessageBusInterface $bus = null,
        ?EntityManagerInterface $em = null,
    ): RetryMarketplaceRawProcessingAction {
        $runRepo = $this->createMock(MarketplaceRawProcessingRunRepository::class);
        $runRepo->method('findByIdAndCompany')->willReturn($run);

        $stepRepo = $this->createMock(MarketplaceRawProcessingStepRunRepository::class);
        $stepRepo->method('findByRunId')->willReturn($stepRuns);

        return new RetryMarketplaceRawProcessingAction(
            $runRepo,
            $stepRepo,
            $em ?? $this->createMock(EntityManagerInterface::class),
            $bus ?? $this->createMock(MessageBusInterface::class),
        );
    }
}
