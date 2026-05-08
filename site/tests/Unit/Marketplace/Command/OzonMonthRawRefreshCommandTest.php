<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Application\DTO\OzonMonthRawRefreshPlanItem;
use App\Marketplace\Application\OzonMonthRawRefreshPlanner;
use App\Marketplace\Command\OzonMonthRawRefreshCommand;
use App\Marketplace\Message\SyncOzonReportMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class OzonMonthRawRefreshCommandTest extends TestCase
{
    public function testDryRunPrintsPlanAndDoesNotDispatch(): void
    {
        $planner = $this->createMock(OzonMonthRawRefreshPlanner::class);
        $planner->expects(self::once())
            ->method('plan')
            ->with(2026, 4, null)
            ->willReturn([
                $this->planItem('planned', null, '2026-04-01'),
                $this->planItem('skipped', 'finance_locked', '2026-04-02'),
            ]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $tester = $this->makeTester($planner, $bus);
        $exitCode = $tester->execute(['--year' => '2026', '--month' => '4', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('company_id', $tester->getDisplay());
        self::assertStringContainsString('2026-04-01', $tester->getDisplay());
        self::assertStringContainsString('Dry-run завершен', $tester->getDisplay());
    }

    public function testDispatchesOnlyPlannedItems(): void
    {
        $planner = $this->createMock(OzonMonthRawRefreshPlanner::class);
        $planner->method('plan')->willReturn([
            $this->planItem('planned', null, '2026-04-01', 'c1', 'k1'),
            $this->planItem('skipped', 'finance_locked', '2026-04-02', 'c1', 'k1'),
            $this->planItem('planned', null, '2026-04-03', 'c2', 'k2'),
        ]);

        $messages = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(function (object $message) use (&$messages): Envelope {
            $messages[] = $message;

            return new Envelope($message);
        });

        $tester = $this->makeTester($planner, $bus);
        $exitCode = $tester->execute(['--year' => '2026', '--month' => '4']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(2, $messages);
        self::assertSame('2026-04-01', $messages[0]->date);
        self::assertSame('2026-04-03', $messages[1]->date);
        self::assertStringContainsString('planned dispatched: 2', $tester->getDisplay());
        self::assertStringContainsString('skipped: 1', $tester->getDisplay());
        self::assertStringContainsString('skipped by finance_locked: 1', $tester->getDisplay());
    }

    public function testCompanyIdPassedToPlanner(): void
    {
        $planner = $this->createMock(OzonMonthRawRefreshPlanner::class);
        $planner->expects(self::once())
            ->method('plan')
            ->with(2026, 4, '11111111-1111-1111-1111-111111111111')
            ->willReturn([]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $tester = $this->makeTester($planner, $bus);
        $exitCode = $tester->execute([
            '--year' => '2026',
            '--month' => '4',
            '--company-id' => '11111111-1111-1111-1111-111111111111',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testPreviousMonthUsesClock(): void
    {
        $planner = $this->createMock(OzonMonthRawRefreshPlanner::class);
        $planner->expects(self::once())
            ->method('plan')
            ->with(2026, 4, null)
            ->willReturn([]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $tester = $this->makeTester($planner, $bus, new MockClock('2026-05-08 12:00:00'));
        $exitCode = $tester->execute(['--previous-month' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testPreviousMonthUsesMoscowTimezoneBoundary(): void
    {
        $planner = $this->createMock(OzonMonthRawRefreshPlanner::class);
        $planner->expects(self::once())
            ->method('plan')
            ->with(2026, 3, null)
            ->willReturn([]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $tester = $this->makeTester($planner, $bus, new MockClock('2026-03-31 22:30:00+00:00'));
        $exitCode = $tester->execute(['--previous-month' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testInvalidInputReturnsFailure(): void
    {
        $planner = $this->createMock(OzonMonthRawRefreshPlanner::class);
        $planner->expects(self::never())->method('plan');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $tester = $this->makeTester($planner, $bus);

        self::assertSame(Command::FAILURE, $tester->execute(['--previous-month' => true, '--year' => '2026', '--month' => '4']));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame(Command::FAILURE, $tester->execute(['--year' => '2026']));
        self::assertSame(Command::FAILURE, $tester->execute(['--month' => '4']));
        self::assertSame(Command::FAILURE, $tester->execute(['--year' => '2026', '--month' => '13']));
        self::assertSame(Command::FAILURE, $tester->execute(['--year' => '2026foo', '--month' => '4']));
        self::assertSame(Command::FAILURE, $tester->execute(['--year' => '2026', '--month' => '4bar']));
    }

    public function testEmptyPlanReturnsSuccessWithoutDispatch(): void
    {
        $planner = $this->createMock(OzonMonthRawRefreshPlanner::class);
        $planner->method('plan')->willReturn([]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $tester = $this->makeTester($planner, $bus);
        $exitCode = $tester->execute(['--year' => '2026', '--month' => '4']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('План пуст', $tester->getDisplay());
    }

    private function makeTester(
        OzonMonthRawRefreshPlanner $planner,
        MessageBusInterface $bus,
        ?MockClock $clock = null,
    ): CommandTester {
        $command = new OzonMonthRawRefreshCommand($planner, $bus, $clock ?? new MockClock('2026-05-08 12:00:00'));

        return new CommandTester($command);
    }

    private function planItem(
        string $status,
        ?string $reason,
        string $date,
        string $companyId = '11111111-1111-1111-1111-111111111111',
        string $connectionId = '22222222-2222-2222-2222-222222222222',
    ): OzonMonthRawRefreshPlanItem {
        return new OzonMonthRawRefreshPlanItem(
            companyId: $companyId,
            connectionId: $connectionId,
            marketplace: 'ozon',
            date: $date,
            status: $status,
            skippedReason: $reason,
        );
    }
}
