<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Command;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\Service\AdBatchPlanner;
use App\MarketplaceAds\Command\AdDailySyncCommand;
use App\MarketplaceAds\Exception\OzonRateLimitException;
use App\MarketplaceAds\Infrastructure\Query\ActiveOzonPerformanceConnectionsQuery;
use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AdDailySyncCommandTest extends TestCase
{
    private const COMPANY_1 = '11111111-1111-1111-1111-000000000001';
    private const COMPANY_2 = '11111111-1111-1111-1111-000000000002';
    private const COMPANY_3 = '11111111-1111-1111-1111-000000000003';

    public function testCreatesJobPerCompanyWithYesterdayUtcDate(): void
    {
        $yesterday = (new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC')))->setTime(0, 0);
        $companyIds = [self::COMPANY_1, self::COMPANY_2, self::COMPANY_3];

        $query = $this->createMock(ActiveOzonPerformanceConnectionsQuery::class);
        $query->method('getCompanyIds')->willReturn($companyIds);

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo
            ->expects(self::exactly(3))
            ->method('existsByDateRange')
            ->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        // One persist per company (the new AdLoadJob).
        $em->expects(self::exactly(3))->method('persist');
        // 2 flushes per company: после persist job'а и после markRunning.
        $em->expects(self::exactly(6))->method('flush');

        $planner = $this->createMock(AdBatchPlanner::class);
        $seenCompanies = [];
        $planner
            ->expects(self::exactly(3))
            ->method('planBatchesForJob')
            ->willReturnCallback(function (string $jobId, string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to) use ($yesterday, &$seenCompanies): int {
                self::assertEquals($yesterday, $from);
                self::assertEquals($yesterday, $to);
                $seenCompanies[] = $companyId;

                return 1;
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $tester = $this->makeTester($query, $planner, $jobRepo, $em, $logger);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame($companyIds, $seenCompanies);

        $display = $tester->getDisplay();
        self::assertStringContainsString(sprintf('date=%s', $yesterday->format('Y-m-d')), $display);
        self::assertStringContainsString('created=3 skipped=0 failed=0', $display);
    }

    public function testEmptyCompanyListExitsSuccess(): void
    {
        $query = $this->createMock(ActiveOzonPerformanceConnectionsQuery::class);
        $query->method('getCompanyIds')->willReturn([]);

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->expects(self::never())->method('existsByDateRange');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $planner = $this->createMock(AdBatchPlanner::class);
        $planner->expects(self::never())->method('planBatchesForJob');

        $logger = $this->createMock(LoggerInterface::class);

        $tester = $this->makeTester($query, $planner, $jobRepo, $em, $logger);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No companies with active Ozon Performance connection', $tester->getDisplay());
    }

    public function testSkipsCompanyWhenJobAlreadyExistsForYesterday(): void
    {
        $companyIds = [self::COMPANY_1, self::COMPANY_2];

        $query = $this->createMock(ActiveOzonPerformanceConnectionsQuery::class);
        $query->method('getCompanyIds')->willReturn($companyIds);

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo
            ->method('existsByDateRange')
            ->willReturnCallback(fn (string $companyId): bool => self::COMPANY_1 === $companyId);

        $em = $this->createMock(EntityManagerInterface::class);

        $planner = $this->createMock(AdBatchPlanner::class);
        $plannedCompanies = [];
        $planner
            ->expects(self::once())
            ->method('planBatchesForJob')
            ->willReturnCallback(function (string $jobId, string $companyId) use (&$plannedCompanies): int {
                $plannedCompanies[] = $companyId;

                return 2;
            });

        $logger = $this->createMock(LoggerInterface::class);

        $tester = $this->makeTester($query, $planner, $jobRepo, $em, $logger);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([self::COMPANY_2], $plannedCompanies, 'Only the company without existing job gets planned');
        self::assertStringContainsString('created=1 skipped=1 failed=0', $tester->getDisplay());
    }

    public function testContinuesAfterPlannerFailureOnOneCompany(): void
    {
        $companyIds = [self::COMPANY_1, self::COMPANY_2, self::COMPANY_3];

        $query = $this->createMock(ActiveOzonPerformanceConnectionsQuery::class);
        $query->method('getCompanyIds')->willReturn($companyIds);

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->method('existsByDateRange')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);

        $planner = $this->createMock(AdBatchPlanner::class);
        $attempted = [];
        $planner
            ->expects(self::exactly(3))
            ->method('planBatchesForJob')
            ->willReturnCallback(function (string $jobId, string $companyId) use (&$attempted): int {
                $attempted[] = $companyId;

                if (self::COMPANY_2 === $companyId) {
                    throw new \RuntimeException('No SKU campaigns found');
                }

                return 1;
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'Daily sync: company failed',
                self::callback(function (array $ctx): bool {
                    return self::COMPANY_2 === ($ctx['companyId'] ?? null)
                        && str_contains((string) ($ctx['error'] ?? ''), 'No SKU campaigns');
                }),
            );

        $tester = $this->makeTester($query, $planner, $jobRepo, $em, $logger);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame($companyIds, $attempted, 'Sibling companies are processed even after one fails');
        self::assertStringContainsString('created=2 skipped=0 failed=1', $tester->getDisplay());
    }

    public function testExistsByDateRangeCalledWithYesterdayInUtc(): void
    {
        $expectedYesterday = (new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC')))->setTime(0, 0);

        $query = $this->createMock(ActiveOzonPerformanceConnectionsQuery::class);
        $query->method('getCompanyIds')->willReturn([self::COMPANY_1]);

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo
            ->expects(self::once())
            ->method('existsByDateRange')
            ->with(
                self::COMPANY_1,
                MarketplaceType::OZON->value,
                self::callback(fn (\DateTimeImmutable $d) => $d == $expectedYesterday),
                self::callback(fn (\DateTimeImmutable $d) => $d == $expectedYesterday),
            )
            ->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $planner = $this->createMock(AdBatchPlanner::class);
        $planner->expects(self::never())->method('planBatchesForJob');

        $logger = $this->createMock(LoggerInterface::class);

        $tester = $this->makeTester($query, $planner, $jobRepo, $em, $logger);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testPlannerRateLimitLogsWarningAndNotError(): void
    {
        $query = $this->createMock(ActiveOzonPerformanceConnectionsQuery::class);
        $query->method('getCompanyIds')->willReturn([self::COMPANY_1]);

        $jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $jobRepo->method('existsByDateRange')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        // persist + markFailed
        $em->expects(self::exactly(2))->method('flush');

        $planner = $this->createMock(AdBatchPlanner::class);
        $planner->method('planBatchesForJob')
            ->willThrowException(new OzonRateLimitException('HTTP 429 Превышен лимит активных запросов'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $logger->expects(self::never())->method('error');

        $tester = $this->makeTester($query, $planner, $jobRepo, $em, $logger);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('created=0 skipped=0 failed=1', $tester->getDisplay());
    }

    private function makeTester(
        ActiveOzonPerformanceConnectionsQuery $query,
        AdBatchPlanner $planner,
        AdLoadJobRepositoryInterface $jobRepo,
        EntityManagerInterface $em,
        LoggerInterface $logger,
    ): CommandTester {
        $command = new AdDailySyncCommand($query, $planner, $jobRepo, $em, $logger);

        return new CommandTester($command);
    }
}
