<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Command;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DispatchOzonAdLoadActionInterface;
use App\MarketplaceAds\Command\ReconcileMarketplaceAdsCommand;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\MarketplaceAds\Exception\OzonRateLimitException;
use App\MarketplaceAds\Infrastructure\Query\ActiveOzonPerformanceConnectionsQuery;
use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReconcileMarketplaceAdsCommandTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';

    public function testDryRunCreatesNothing(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn(null);
        $repo->method('findLatestJobCoveringDate')->willReturn(null);

        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::never())->method('__invoke');

        $tester = $this->tester($repo, $dispatch);
        $code = $tester->execute([
            '--company' => self::COMPANY_ID,
            '--from' => '2026-04-01',
            '--to' => '2026-04-02',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('would_create=2 created=0', $tester->getDisplay());
    }

    public function testCompletedJobIsNotReloaded(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn(null);
        $repo->method('findCompletedJobCoveringDate')->willReturn($this->job(AdLoadJobStatus::COMPLETED));

        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::never())->method('__invoke');

        $tester = $this->tester($repo, $dispatch);
        $code = $tester->execute([
            '--company' => self::COMPANY_ID,
            '--from' => '2026-04-01',
            '--to' => '2026-04-01',
        ]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('skip: completed', $tester->getDisplay());
    }

    public function testFailedJobDoesNotBlockReconcile(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn(null);
        $repo->method('findCompletedJobCoveringDate')->willReturn(null);
        $repo->method('findLatestJobCoveringDate')->willReturn($this->job(AdLoadJobStatus::FAILED, 'HTTP 500'));

        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::once())->method('__invoke');

        $tester = $this->tester($repo, $dispatch);
        $code = $tester->execute([
            '--company' => self::COMPANY_ID,
            '--from' => '2026-04-01',
            '--to' => '2026-04-01',
            '--include-failed' => true,
        ]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('reload: failed_transient', $tester->getDisplay());
    }

    public function testRecognizes429AsRateLimited(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn(null);
        $repo->method('findCompletedJobCoveringDate')->willReturn(null);
        $repo->method('findLatestJobCoveringDate')->willReturn($this->job(AdLoadJobStatus::FAILED, 'Marketplace API rate limit exceeded'));        

        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::never())->method('__invoke');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $command = new ReconcileMarketplaceAdsCommand($repo, $dispatch, $this->emptyConnectionsQuery(), $logger);
        $tester = new CommandTester($command);
        $code = $tester->execute([
            '--company' => self::COMPANY_ID,
            '--from' => '2026-04-01',
            '--to' => '2026-04-01',
        ]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('skip: rate_limited', $tester->getDisplay());
    }

    public function testContinuesIfOneDayFails(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn(null);
        $repo->method('findCompletedJobCoveringDate')->willReturn(null);
        $repo->method('findLatestJobCoveringDate')->willReturn(null);

        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $calls = 0;
        $dispatch->method('__invoke')->willReturnCallback(function () use (&$calls): void {
            ++$calls;
            if (1 === $calls) {
                throw new \RuntimeException('boom');
            }
        });

        $tester = $this->tester($repo, $dispatch);
        $code = $tester->execute([
            '--company' => self::COMPANY_ID,
            '--from' => '2026-04-01',
            '--to' => '2026-04-02',
        ]);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('failed=1', $tester->getDisplay());
    }

    public function testRealRunCreatesOnlyOneJobAndDefersRemainingDays(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn(null);
        $repo->method('findCompletedJobCoveringDate')->willReturn(null);
        $repo->method('findLatestJobCoveringDate')->willReturn(null);
        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::once())->method('__invoke');
        $tester = $this->tester($repo, $dispatch);
        $code = $tester->execute([
            '--company' => self::COMPANY_ID,
            '--from' => '2026-04-01',
            '--to' => '2026-04-03',
        ]);
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('remaining=2', $tester->getDisplay());
    }

    public function testRealRunMissingCompletedMissingShowsSkipAndDeferredAndRemainingOne(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn(null);
        $repo->method('findCompletedJobCoveringDate')->willReturnCallback(
            fn (string $companyId, MarketplaceType $marketplace, \DateTimeImmutable $date): ?AdLoadJob => '2026-04-02' === $date->format('Y-m-d')
                ? $this->job(AdLoadJobStatus::COMPLETED)
                : null
        );
        $repo->method('findLatestJobCoveringDate')->willReturn(null);

        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::once())->method('__invoke');

        $tester = $this->tester($repo, $dispatch);
        $code = $tester->execute([
            '--company' => self::COMPANY_ID,
            '--from' => '2026-04-01',
            '--to' => '2026-04-03',
        ]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('2026-04-02 skip: completed', $tester->getDisplay());
        self::assertStringContainsString('remaining=1', $tester->getDisplay());
    }

    public function testCompletedJobWinsOverLatestFailedJob(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn(null);
        $repo->method('findCompletedJobCoveringDate')->willReturn($this->job(AdLoadJobStatus::COMPLETED));
        $repo->method('findLatestJobCoveringDate')->willReturn($this->job(AdLoadJobStatus::FAILED, 'HTTP 500'));
        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::never())->method('__invoke');
        $tester = $this->tester($repo, $dispatch);
        $tester->execute([
            '--company' => self::COMPANY_ID,
            '--from' => '2026-04-01',
            '--to' => '2026-04-01',
            '--include-failed' => true,
        ]);
        self::assertStringContainsString('skip: completed', $tester->getDisplay());
    }

    public function testEmptyCompanyOptionReturnsInvalid(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::never())->method('__invoke');
        $tester = $this->tester($repo, $dispatch);
        $code = $tester->execute(['--company' => '', '--from' => '2026-04-01', '--to' => '2026-04-01']);
        self::assertSame(Command::INVALID, $code);
    }

    public function testExistingActiveJobIsDeferredWithoutDispatch(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn($this->job(AdLoadJobStatus::RUNNING));
        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::never())->method('__invoke');
        $tester = $this->tester($repo, $dispatch);
        $tester->execute(['--company' => self::COMPANY_ID, '--from' => '2026-04-01', '--to' => '2026-04-01']);
        self::assertStringContainsString('deferred', $tester->getDisplay());
    }

    public function testFreshRateLimitDuringDispatchIsDeferredWithWarningNotError(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn(null);
        $repo->method('findCompletedJobCoveringDate')->willReturn(null);
        $repo->method('findLatestJobCoveringDate')->willReturn(null);

        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->method('__invoke')->willThrowException(
            new \RuntimeException('Failed to plan ad load job: rate_limited', 0, new OzonRateLimitException('429'))
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $logger->expects(self::never())->method('error');

        $command = new ReconcileMarketplaceAdsCommand($repo, $dispatch, $this->emptyConnectionsQuery(), $logger);
        $tester = new CommandTester($command);
        $code = $tester->execute([
            '--company' => self::COMPANY_ID,
            '--from' => '2026-04-01',
            '--to' => '2026-04-01',
            '--include-rate-limited' => true,
        ]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('deferred: rate_limited', $tester->getDisplay());
        self::assertStringContainsString('failed=0', $tester->getDisplay());
        self::assertStringContainsString('deferred=1', $tester->getDisplay());
    }

    public function testFreshRateLimitStopsFurtherDispatchAttemptsInSameRun(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn(null);
        $repo->method('findCompletedJobCoveringDate')->willReturn(null);
        $repo->method('findLatestJobCoveringDate')->willReturn(null);

        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::once())->method('__invoke')
            ->willThrowException(new \RuntimeException(
                'Failed to plan ad load job: rate_limited',
                0,
                new OzonRateLimitException('429'),
            ));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('warning');
        $logger->expects(self::never())->method('error');

        $command = new ReconcileMarketplaceAdsCommand($repo, $dispatch, $this->emptyConnectionsQuery(), $logger);
        $tester = new CommandTester($command);
        $code = $tester->execute([
            '--company' => self::COMPANY_ID,
            '--from' => '2026-04-01',
            '--to' => '2026-04-03',
            '--include-rate-limited' => true,
        ]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('failed=0', $tester->getDisplay());
        self::assertStringContainsString('deferred=3', $tester->getDisplay());
    }


    public function testAllActiveUsesConnectionsQueryAndProcessesEachCompany(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn(null);
        $repo->method('findCompletedJobCoveringDate')->willReturn(null);
        $repo->method('findLatestJobCoveringDate')->willReturn(null);

        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::exactly(2))->method('__invoke');

        $query = $this->createMock(ActiveOzonPerformanceConnectionsQuery::class);
        $query->expects(self::once())->method('getCompanyIds')->willReturn(['c1', 'c2']);

        $tester = $this->tester($repo, $dispatch, $query);
        $code = $tester->execute(['--all-active' => true, '--from' => '2026-04-01', '--to' => '2026-04-01']);

        self::assertSame(Command::SUCCESS, $code);
    }

    public function testAllActiveDoesNotRequireCompany(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn(null);
        $repo->method('findCompletedJobCoveringDate')->willReturn(null);
        $repo->method('findLatestJobCoveringDate')->willReturn(null);
        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::never())->method('__invoke');

        $query = $this->createMock(ActiveOzonPerformanceConnectionsQuery::class);
        $query->method('getCompanyIds')->willReturn([]);

        $tester = $this->tester($repo, $dispatch, $query);
        $code = $tester->execute(['--all-active' => true, '--from' => '2026-04-01', '--to' => '2026-04-01']);
        self::assertSame(Command::SUCCESS, $code);
    }

    public function testDaysBackBuildsUtcRangeUntilYesterday(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn(null);
        $repo->method('findCompletedJobCoveringDate')->willReturn(null);
        $repo->method('findLatestJobCoveringDate')->willReturn(null);

        $todayUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTime(0, 0);
        $expectedFrom = $todayUtc->modify('-14 days')->format('Y-m-d');
        $expectedTo = $todayUtc->modify('-1 day')->format('Y-m-d');

        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::once())->method('__invoke')
            ->with(self::COMPANY_ID, self::callback(fn (\DateTimeImmutable $d): bool => $expectedFrom === $d->format('Y-m-d')), self::anything());

        $tester = $this->tester($repo, $dispatch);
        $code = $tester->execute(['--company' => self::COMPANY_ID, '--days-back' => '14']);
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString($expectedTo, $tester->getDisplay());
    }

    public function testRateLimitInOneCompanyDoesNotBlockAnotherInAllActiveMode(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn(null);
        $repo->method('findCompletedJobCoveringDate')->willReturn(null);
        $repo->method('findLatestJobCoveringDate')->willReturn(null);

        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::exactly(2))->method('__invoke')
            ->willReturnCallback(function (string $companyId): void {
                if ('c1' === $companyId) {
                    throw new \RuntimeException('rate_limited', 0, new OzonRateLimitException('429'));
                }
            });

        $query = $this->createMock(ActiveOzonPerformanceConnectionsQuery::class);
        $query->method('getCompanyIds')->willReturn(['c1', 'c2']);

        $tester = $this->tester($repo, $dispatch, $query);
        $code = $tester->execute(['--all-active' => true, '--from' => '2026-04-01', '--to' => '2026-04-01']);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('[c2]', $tester->getDisplay());
    }


    public function testActiveCompanyJobForAnotherDateDefersMissingDayWithoutDispatchAndWithoutErrorLog(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->method('findLatestActiveJobByCompanyAndMarketplace')->willReturn($this->job(AdLoadJobStatus::RUNNING));
        $repo->method('findCompletedJobCoveringDate')->willReturn(null);
        $repo->method('findLatestJobCoveringDate')->willReturn(null);

        $dispatch = $this->createMock(DispatchOzonAdLoadActionInterface::class);
        $dispatch->expects(self::never())->method('__invoke');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $command = new ReconcileMarketplaceAdsCommand($repo, $dispatch, $this->emptyConnectionsQuery(), $logger);
        $tester = new CommandTester($command);
        $code = $tester->execute(['--company' => self::COMPANY_ID, '--from' => '2026-04-01', '--to' => '2026-04-01']);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('deferred: active pipeline already running for company', $tester->getDisplay());
    }

    private function tester(AdLoadJobRepositoryInterface $repo, DispatchOzonAdLoadActionInterface $dispatch, ?ActiveOzonPerformanceConnectionsQuery $query = null): CommandTester
    {
        $logger = $this->createMock(LoggerInterface::class);
        $command = new ReconcileMarketplaceAdsCommand($repo, $dispatch, $query ?? $this->emptyConnectionsQuery(), $logger);

        return new CommandTester($command);
    }


    private function emptyConnectionsQuery(): ActiveOzonPerformanceConnectionsQuery
    {
        $query = $this->createMock(ActiveOzonPerformanceConnectionsQuery::class);
        $query->method('getCompanyIds')->willReturn([]);

        return $query;
    }

    private function job(AdLoadJobStatus $status, ?string $reason = null): AdLoadJob
    {
        $job = new AdLoadJob(self::COMPANY_ID, MarketplaceType::OZON, new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-01'));

        if (AdLoadJobStatus::COMPLETED === $status) {
            $job->markRunning();
            $job->markCompleted();
        }
        if (AdLoadJobStatus::FAILED === $status) {
            $job->markRunning();
            $job->markFailed($reason ?? 'failed');
        }
        if (AdLoadJobStatus::RUNNING === $status) {
            $job->markRunning();
        }

        return $job;
    }
}
