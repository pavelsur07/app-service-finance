<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Application\OzonMonthRawRefreshPlanner;
use App\Marketplace\Command\OzonMonthRawRefreshCommand;
use App\Marketplace\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Message\SyncOzonReportMessage;
use Doctrine\DBAL\Connection;
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
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $tester = $this->makeTesterWithConnectionRows([
            [
                'id' => '22222222-2222-2222-2222-222222222222',
                'company_id' => '11111111-1111-1111-1111-111111111111',
                'finance_lock_before' => null,
            ],
        ], $bus);

        $exitCode = $tester->execute(['--year' => '2026', '--month' => '4', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('company_id', $tester->getDisplay());
        self::assertStringContainsString('2026-04-01', $tester->getDisplay());
        self::assertStringContainsString('Dry-run завершен', $tester->getDisplay());
    }

    public function testDispatchesOnlyPlannedItems(): void
    {
        $messages = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(function (object $message) use (&$messages): Envelope {
            $messages[] = $message;

            return new Envelope($message);
        });

        $tester = $this->makeTesterWithConnectionRows([
            [
                'id' => '22222222-2222-2222-2222-222222222222',
                'company_id' => '11111111-1111-1111-1111-111111111111',
                'finance_lock_before' => '2026-04-28',
            ],
        ], $bus);

        $exitCode = $tester->execute(['--year' => '2026', '--month' => '4']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(2, $messages);
        self::assertInstanceOf(SyncOzonReportMessage::class, $messages[0]);
        self::assertInstanceOf(SyncOzonReportMessage::class, $messages[1]);
        self::assertSame('2026-04-29', $messages[0]->date);
        self::assertSame('2026-04-30', $messages[1]->date);
        self::assertStringContainsString('planned dispatched: 2', $tester->getDisplay());
        self::assertStringContainsString('skipped: 28', $tester->getDisplay());
        self::assertStringContainsString('skipped by finance_locked: 28', $tester->getDisplay());
    }

    public function testCompanyIdPassedToPlanner(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $tester = $this->makeTesterWithConnectionRows([
            [
                'id' => '22222222-2222-2222-2222-222222222222',
                'company_id' => '11111111-1111-1111-1111-111111111111',
                'finance_lock_before' => null,
            ],
        ], $bus, null, static function (string $sql, array $params): void {
            self::assertStringContainsString('mc.company_id = :company_id', $sql);
            self::assertArrayHasKey('company_id', $params);
            self::assertSame('11111111-1111-1111-1111-111111111111', $params['company_id']);
        });

        $exitCode = $tester->execute([
            '--year' => '2026',
            '--month' => '4',
            '--company-id' => '11111111-1111-1111-1111-111111111111',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testPreviousMonthUsesClock(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $tester = $this->makeTesterWithConnectionRows([
            [
                'id' => '22222222-2222-2222-2222-222222222222',
                'company_id' => '11111111-1111-1111-1111-111111111111',
                'finance_lock_before' => null,
            ],
        ], $bus, new MockClock('2026-05-08 12:00:00'));

        $exitCode = $tester->execute(['--previous-month' => true, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('2026-04-01', $tester->getDisplay());
        self::assertStringContainsString('2026-04-30', $tester->getDisplay());
    }

    public function testPreviousMonthUsesMoscowTimezoneBoundary(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $tester = $this->makeTesterWithConnectionRows([
            [
                'id' => '22222222-2222-2222-2222-222222222222',
                'company_id' => '11111111-1111-1111-1111-111111111111',
                'finance_lock_before' => null,
            ],
        ], $bus, new MockClock('2026-03-31 22:30:00+00:00'));

        $exitCode = $tester->execute(['--previous-month' => true, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('2026-03-01', $tester->getDisplay());
        self::assertStringContainsString('2026-03-31', $tester->getDisplay());
    }

    public function testInvalidInputReturnsFailure(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAllAssociative');

        $query = new ActiveOzonConnectionsQuery($connection);
        $planner = new OzonMonthRawRefreshPlanner($query);
        $tester = new CommandTester(new OzonMonthRawRefreshCommand($planner, $bus, new MockClock('2026-05-08 12:00:00')));

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
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $tester = $this->makeTesterWithConnectionRows([], $bus);
        $exitCode = $tester->execute(['--year' => '2026', '--month' => '4']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('План пуст', $tester->getDisplay());
    }

    private function makeTesterWithConnectionRows(
        array $connectionRows,
        MessageBusInterface $bus,
        ?MockClock $clock = null,
        ?callable $connectionAssert = null,
    ): CommandTester {
        $dbal = $this->createMock(Connection::class);
        $dbal->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $params = []) use ($connectionRows, $connectionAssert): array {
                if ($connectionAssert !== null) {
                    $connectionAssert($sql, $params);
                }

                return $connectionRows;
            });

        $query = new ActiveOzonConnectionsQuery($dbal);
        $planner = new OzonMonthRawRefreshPlanner($query);

        $command = new OzonMonthRawRefreshCommand(
            $planner,
            $bus,
            $clock ?? new MockClock('2026-05-08 12:00:00'),
        );

        return new CommandTester($command);
    }
}
