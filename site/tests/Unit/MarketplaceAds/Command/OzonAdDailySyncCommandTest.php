<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Command;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DispatchOzonAdLoadAction;
use App\MarketplaceAds\Command\OzonAdDailySyncCommand;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Infrastructure\Query\ActiveOzonPerformanceConnectionsQuery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonAdDailySyncCommandTest extends TestCase
{
    private const COMPANY_1 = '11111111-1111-1111-1111-000000000001';
    private const COMPANY_2 = '11111111-1111-1111-1111-000000000002';
    private const COMPANY_3 = '11111111-1111-1111-1111-000000000003';

    public function testDispatchesForEachCompanyWithYesterdayDate(): void
    {
        $yesterday = (new \DateTimeImmutable('yesterday'))->setTime(0, 0);
        $companyIds = [self::COMPANY_1, self::COMPANY_2, self::COMPANY_3];

        $query = $this->createMock(ActiveOzonPerformanceConnectionsQuery::class);
        $query->method('getCompanyIds')->willReturn($companyIds);

        $action = $this->createMock(DispatchOzonAdLoadAction::class);

        $receivedCompanies = [];
        $action
            ->expects(self::exactly(3))
            ->method('__invoke')
            ->willReturnCallback(function (string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to) use ($yesterday, &$receivedCompanies): AdLoadJob {
                self::assertEquals($yesterday, $from);
                self::assertEquals($yesterday, $to);
                $receivedCompanies[] = $companyId;

                return new AdLoadJob($companyId, MarketplaceType::OZON, $from, $to);
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::never())->method('error');

        $tester = $this->makeTester($query, $action, $logger);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame($companyIds, $receivedCompanies);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Dispatched 1 of 3 companies', $display);
        self::assertStringContainsString('Dispatched 2 of 3 companies', $display);
        self::assertStringContainsString('Dispatched 3 of 3 companies', $display);
    }

    public function testSucceedsWhenNoCompanies(): void
    {
        $query = $this->createMock(ActiveOzonPerformanceConnectionsQuery::class);
        $query->method('getCompanyIds')->willReturn([]);

        $action = $this->createMock(DispatchOzonAdLoadAction::class);
        $action->expects(self::never())->method('__invoke');

        $logger = $this->createMock(LoggerInterface::class);

        $tester = $this->makeTester($query, $action, $logger);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Нет активных Ozon Performance подключений', $tester->getDisplay());
    }

    public function testContinuesAfterDomainExceptionOnOneCompany(): void
    {
        $companyIds = [self::COMPANY_1, self::COMPANY_2, self::COMPANY_3];

        $query = $this->createMock(ActiveOzonPerformanceConnectionsQuery::class);
        $query->method('getCompanyIds')->willReturn($companyIds);

        $action = $this->createMock(DispatchOzonAdLoadAction::class);
        $received = [];
        $action
            ->expects(self::exactly(3))
            ->method('__invoke')
            ->willReturnCallback(function (string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to) use (&$received): AdLoadJob {
                $received[] = $companyId;

                if (self::COMPANY_2 === $companyId) {
                    throw new \DomainException('Load already in progress');
                }

                return new AdLoadJob($companyId, MarketplaceType::OZON, $from, $to);
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $logger->expects(self::never())->method('error');

        $tester = $this->makeTester($query, $action, $logger);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame($companyIds, $received, 'All companies must be attempted, even after a DomainException');
    }

    public function testContinuesAfterThrowableOnOneCompany(): void
    {
        $companyIds = [self::COMPANY_1, self::COMPANY_2, self::COMPANY_3];

        $query = $this->createMock(ActiveOzonPerformanceConnectionsQuery::class);
        $query->method('getCompanyIds')->willReturn($companyIds);

        $action = $this->createMock(DispatchOzonAdLoadAction::class);
        $received = [];
        $action
            ->expects(self::exactly(3))
            ->method('__invoke')
            ->willReturnCallback(function (string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to) use (&$received): AdLoadJob {
                $received[] = $companyId;

                if (self::COMPANY_1 === $companyId) {
                    throw new \RuntimeException('API timeout');
                }

                return new AdLoadJob($companyId, MarketplaceType::OZON, $from, $to);
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::once())->method('error');

        $tester = $this->makeTester($query, $action, $logger);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame($companyIds, $received, 'All companies must be attempted, even after a Throwable');
    }

    private function makeTester(
        ActiveOzonPerformanceConnectionsQuery $query,
        DispatchOzonAdLoadAction $action,
        LoggerInterface $logger,
    ): CommandTester {
        $command = new OzonAdDailySyncCommand($query, $action, $logger);

        return new CommandTester($command);
    }
}
