<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\OzonAccrualSyncPlanner;
use App\Marketplace\Message\SyncOzonAccrualByDayMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class OzonAccrualSyncPlannerTest extends TestCase
{
    private const COMPANY = '0192f0c2-0000-7000-8000-000000000001';
    private const CONNECTION = 'conn-1';

    /** @var list<SyncOzonAccrualByDayMessage> */
    private array $messages = [];

    public function testDispatchesOneMessagePerDayNewestFirst(): void
    {
        $result = $this->planner('2026-09-20 09:00:00')->planRange(
            self::COMPANY,
            self::CONNECTION,
            new \DateTimeImmutable('2026-09-15'),
            new \DateTimeImmutable('2026-09-17'),
        );

        self::assertSame(3, $result->dispatchedCount);
        self::assertSame(['2026-09-17', '2026-09-16', '2026-09-15'], $this->dates());
        self::assertSame('2026-09-15', $result->firstDay);
        self::assertSame('2026-09-17', $result->lastDay);
        self::assertFalse($result->clampedToSafeDay);
        foreach ($this->messages as $message) {
            self::assertSame(self::COMPANY, $message->companyId);
            self::assertSame(self::CONNECTION, $message->connectionId);
        }
    }

    public function testStartBeforeSafeDayIsRaisedToIt(): void
    {
        // Дни до 08.09 покрыты документами v3: повторная загрузка by-day дала бы
        // двойной учёт продаж, поэтому начало окна поднимается до границы.
        $result = $this->planner('2026-09-11 09:00:00')->planRange(
            self::COMPANY,
            self::CONNECTION,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-09-10'),
        );

        self::assertTrue($result->clampedToSafeDay);
        self::assertSame(OzonAccrualSyncPlanner::EARLIEST_SAFE_DAY, $result->firstDay);
        self::assertSame(['2026-09-10', '2026-09-09', '2026-09-08'], $this->dates());
    }

    public function testEndIsCappedAtYesterdayInMoscow(): void
    {
        // 23:30 UTC 19.09 — это уже 20.09 по Москве, значит «вчера» — 19.09.
        $result = $this->planner('2026-09-19 23:30:00', 'UTC')->planRange(
            self::COMPANY,
            self::CONNECTION,
            new \DateTimeImmutable('2026-09-18'),
            new \DateTimeImmutable('2026-09-25'),
        );

        self::assertSame('2026-09-19', $result->lastDay);
        self::assertSame(['2026-09-19', '2026-09-18'], $this->dates());
    }

    public function testWindowEntirelyBeforeSafeDayDispatchesNothing(): void
    {
        $result = $this->planner('2026-09-20 09:00:00')->planRange(
            self::COMPANY,
            self::CONNECTION,
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-31'),
        );

        self::assertSame(0, $result->dispatchedCount);
        self::assertNull($result->firstDay);
        self::assertNull($result->lastDay);
        self::assertTrue($result->clampedToSafeDay);
        self::assertSame([], $this->messages);
    }

    public function testTodayOnlyDispatchesNothing(): void
    {
        $result = $this->planner('2026-09-20 09:00:00')->planRange(
            self::COMPANY,
            self::CONNECTION,
            new \DateTimeImmutable('2026-09-20'),
            new \DateTimeImmutable('2026-09-20'),
        );

        self::assertSame(0, $result->dispatchedCount);
        self::assertSame([], $this->messages);
    }

    public function testReversedRangeIsRejected(): void
    {
        $this->expectException(\DomainException::class);

        $this->planner('2026-09-20 09:00:00')->planRange(
            self::COMPANY,
            self::CONNECTION,
            new \DateTimeImmutable('2026-09-15'),
            new \DateTimeImmutable('2026-09-14'),
        );
    }

    private function planner(string $now, string $timezone = 'Europe/Moscow'): OzonAccrualSyncPlanner
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            self::assertInstanceOf(SyncOzonAccrualByDayMessage::class, $message);
            $this->messages[] = $message;

            return new Envelope($message);
        });

        return new OzonAccrualSyncPlanner($bus, new NullLogger(), new MockClock($now, $timezone));
    }

    /**
     * @return list<string>
     */
    private function dates(): array
    {
        return array_map(static fn (SyncOzonAccrualByDayMessage $m): string => $m->date, $this->messages);
    }
}
