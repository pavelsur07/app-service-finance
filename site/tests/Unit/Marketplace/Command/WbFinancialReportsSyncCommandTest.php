<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlannerInterface;
use App\Marketplace\Command\WbFinancialReportsSyncCommand;
use App\Marketplace\Enum\FinancialReportSyncMode;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WbFinancialReportsSyncCommandTest extends TestCase
{
    private WbFinancialReportSyncPlannerInterface&MockObject $planner;

    protected function setUp(): void
    {
        $this->planner = $this->createMock(WbFinancialReportSyncPlannerInterface::class);
    }

    public function testInvalidModeReturnsFailure(): void
    {
        $tester = $this->tester();

        $code = $tester->execute(['--mode' => 'bad']);

        self::assertSame(Command::FAILURE, $code);
    }

    public function testFromWithoutToReturnsFailure(): void
    {
        $tester = $this->tester();

        $code = $tester->execute(['--mode' => 'daily', '--from' => '2026-05-19']);

        self::assertSame(Command::FAILURE, $code);
    }

    public function testToWithoutFromReturnsFailure(): void
    {
        $tester = $this->tester();

        $code = $tester->execute(['--mode' => 'daily', '--to' => '2026-05-19']);

        self::assertSame(Command::FAILURE, $code);
    }

    public function testFromGreaterThanToReturnsFailure(): void
    {
        $tester = $this->tester();

        $code = $tester->execute(['--mode' => 'daily', '--from' => '2026-05-20', '--to' => '2026-05-19']);

        self::assertSame(Command::FAILURE, $code);
    }

    public function testAllWithDateReturnsFailure(): void
    {
        $tester = $this->tester();

        $code = $tester->execute(['--mode' => 'all', '--date' => '2026-05-19']);

        self::assertSame(Command::FAILURE, $code);
    }


    public function testMissingWithDateReturnsFailureAndDoesNotCallPlanMissing(): void
    {
        $this->planner->expects(self::never())->method('planMissing');

        $tester = $this->tester();

        $code = $tester->execute(['--mode' => 'missing', '--date' => '2026-05-19']);

        self::assertSame(Command::FAILURE, $code);
    }

    public function testDailyDateCallsPlanRangeForOneDay(): void
    {
        $this->planner
            ->expects(self::once())
            ->method('planRange')
            ->with(
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-05-19' === $d->format('Y-m-d')),
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-05-19' === $d->format('Y-m-d')),
                FinancialReportSyncMode::DAILY,
                null,
                null,
                false,
            )
            ->willReturn(1);

        $this->planner->expects(self::never())->method('planDaily');

        $tester = $this->tester();
        $code = $tester->execute(['--mode' => 'daily', '--date' => '2026-05-19']);

        self::assertSame(Command::SUCCESS, $code);
    }

    public function testInitialDateCallsPlanRangeAndNotPlanInitial(): void
    {
        $this->planner
            ->expects(self::once())
            ->method('planRange')
            ->with(
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-05-19' === $d->format('Y-m-d')),
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-05-19' === $d->format('Y-m-d')),
                FinancialReportSyncMode::INITIAL,
                null,
                null,
                false,
            )
            ->willReturn(1);

        $this->planner->expects(self::never())->method('planInitial');

        $tester = $this->tester();
        $code = $tester->execute(['--mode' => 'initial', '--date' => '2026-05-19']);

        self::assertSame(Command::SUCCESS, $code);
    }

    private function tester(): CommandTester
    {
        $resolver = new WbFinancialReportPeriodResolver(new MockClock('2026-05-21 00:00:00 Europe/Moscow'));
        $command = new WbFinancialReportsSyncCommand($this->planner, $resolver, new NullLogger());

        return new CommandTester($command);
    }
}
