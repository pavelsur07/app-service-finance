<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Command\MonthPreliminaryRebuildCommand;
use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
use App\Marketplace\Infrastructure\Query\PreliminaryClosedPeriodsQuery;
use App\Marketplace\Message\RebuildPreliminaryForPeriodMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MonthPreliminaryRebuildCommandTest extends TestCase
{
    public function testDispatchesMessagePerActiveSellerConnection(): void
    {
        $query = $this->connectionsQuery([
            ['id' => 'c1', 'company_id' => 'company-a', 'marketplace' => 'ozon'],
            ['id' => 'c2', 'company_id' => 'company-a', 'marketplace' => 'wildberries'],
            ['id' => 'c3', 'company_id' => 'company-b', 'marketplace' => 'ozon'],
        ]);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatched): Envelope {
                self::assertInstanceOf(RebuildPreliminaryForPeriodMessage::class, $message);
                $dispatched[] = [
                    'companyId' => $message->companyId,
                    'marketplace' => $message->marketplace,
                ];

                return new Envelope($message);
            });

        $logger = $this->createMock(LoggerInterface::class);

        $command = new MonthPreliminaryRebuildCommand($query, $bus, $logger, new MockClock('2026-09-15 04:45:00'), $this->preliminaryPeriods());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(3, $dispatched);
        self::assertSame(['companyId' => 'company-a', 'marketplace' => 'ozon'], $dispatched[0]);
        self::assertSame(['companyId' => 'company-a', 'marketplace' => 'wildberries'], $dispatched[1]);
        self::assertSame(['companyId' => 'company-b', 'marketplace' => 'ozon'], $dispatched[2]);
    }

    public function testPreliminaryPeriodsAreRebuiltRegardlessOfCalendar(): void
    {
        // Загрузка by-day умеет перезалить день окном до 365 суток: она снимает
        // там привязку к предварительному ОПиУ и заменяет строки. Пересбор,
        // привязанный к календарному окну, оставил бы документ такого месяца
        // расходиться с источником навсегда, поэтому список берётся из
        // состояния — каждый период, закрытый предварительно.
        $dispatched = [];
        $query = $this->connectionsQuery([['company_id' => 'c-1', 'marketplace' => 'ozon']]);
        $bus = $this->bus($dispatched);

        $command = new MonthPreliminaryRebuildCommand($query, $bus, new NullLogger(), new MockClock('2026-09-02 04:45:00'), $this->preliminaryPeriods([['company_id' => 'c-1', 'marketplace' => 'ozon', 'year' => 2026, 'month' => 8]]));
        (new CommandTester($command))->execute([]);

        $periods = array_map(
            static fn (RebuildPreliminaryForPeriodMessage $m): string => sprintf('%d-%02d', $m->year, $m->month),
            $dispatched,
        );

        self::assertSame(['2026-09', '2026-08'], $periods);
    }

    public function testWithoutPreliminaryPeriodsOnlyTheCurrentMonthIsRebuilt(): void
    {
        $dispatched = [];
        $query = $this->connectionsQuery([['company_id' => 'c-1', 'marketplace' => 'ozon']]);
        $bus = $this->bus($dispatched);

        $command = new MonthPreliminaryRebuildCommand($query, $bus, new NullLogger(), new MockClock('2026-09-15 04:45:00'), $this->preliminaryPeriods());
        (new CommandTester($command))->execute([]);

        self::assertCount(1, $dispatched);
        self::assertSame(9, $dispatched[0]->month);
    }

    public function testEmptyConnectionListExitsSuccess(): void
    {
        $query = $this->connectionsQuery([]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);

        $command = new MonthPreliminaryRebuildCommand($query, $bus, $logger, new MockClock('2026-09-15 04:45:00'), $this->preliminaryPeriods());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Нет активных SELLER-подключений', $tester->getDisplay());
    }

    public function testContinuesAfterDispatchFailureOnOneConnection(): void
    {
        $query = $this->connectionsQuery([
            ['id' => 'c1', 'company_id' => 'company-a', 'marketplace' => 'ozon'],
            ['id' => 'c2', 'company_id' => 'company-b', 'marketplace' => 'wildberries'],
            ['id' => 'c3', 'company_id' => 'company-c', 'marketplace' => 'ozon'],
        ]);

        $attempted = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$attempted): Envelope {
                $attempted[] = $message->companyId;

                if ('company-b' === $message->companyId) {
                    throw new \RuntimeException('queue down');
                }

                return new Envelope($message);
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                '[PreliminaryRebuild] Dispatch failed',
                self::callback(static fn (array $ctx): bool => ($ctx['company_id'] ?? null) === 'company-b'),
            );

        $command = new MonthPreliminaryRebuildCommand($query, $bus, $logger, new MockClock('2026-09-15 04:45:00'), $this->preliminaryPeriods());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['company-a', 'company-b', 'company-c'], $attempted);
        self::assertStringContainsString('Отправлено 2', $tester->getDisplay());
        self::assertStringContainsString('ошибок: 1', $tester->getDisplay());
    }

    /**
     * @param list<array<string, mixed>> $connections
     */
    private function connectionsQuery(array $connections): ActiveSellerConnectionsQuery
    {
        $query = $this->createMock(ActiveSellerConnectionsQuery::class);
        $query->method('execute')->willReturn($connections);

        return $query;
    }

    /**
     * @param list<RebuildPreliminaryForPeriodMessage> $dispatched
     */
    private function bus(array &$dispatched): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
            $dispatched[] = $message;

            return new Envelope($message);
        });

        return $bus;
    }

    /**
     * @param list<array{company_id: string, marketplace: string, year: int, month: int}> $periods
     */
    private function preliminaryPeriods(array $periods = []): PreliminaryClosedPeriodsQuery
    {
        $query = (new \ReflectionClass(PreliminaryClosedPeriodsQuery::class))->newInstanceWithoutConstructor();

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($periods);
        (new \ReflectionProperty($query, 'connection'))->setValue($query, $connection);

        return $query;
    }
}
