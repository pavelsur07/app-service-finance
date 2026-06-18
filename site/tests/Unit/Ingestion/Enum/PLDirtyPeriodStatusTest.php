<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Enum;

use App\Ingestion\Enum\PLDirtyPeriodStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PLDirtyPeriodStatusTest extends TestCase
{
    #[DataProvider('transitionProvider')]
    public function testTransitionMatrix(PLDirtyPeriodStatus $from, PLDirtyPeriodStatus $to, bool $expected): void
    {
        self::assertSame($expected, $from->canTransitionTo($to));
    }

    public function testTerminalStatuses(): void
    {
        self::assertFalse(PLDirtyPeriodStatus::PENDING->isTerminal());
        self::assertFalse(PLDirtyPeriodStatus::REBUILDING->isTerminal());
        self::assertTrue(PLDirtyPeriodStatus::DONE->isTerminal());
        self::assertTrue(PLDirtyPeriodStatus::FAILED->isTerminal());
        self::assertTrue(PLDirtyPeriodStatus::BLOCKED_BY_CLOSE->isTerminal());
    }

    /**
     * @return iterable<string, array{PLDirtyPeriodStatus, PLDirtyPeriodStatus, bool}>
     */
    public static function transitionProvider(): iterable
    {
        $allowed = [
            PLDirtyPeriodStatus::PENDING->value => [
                PLDirtyPeriodStatus::REBUILDING->value,
                PLDirtyPeriodStatus::BLOCKED_BY_CLOSE->value,
            ],
            PLDirtyPeriodStatus::REBUILDING->value => [
                PLDirtyPeriodStatus::DONE->value,
                PLDirtyPeriodStatus::FAILED->value,
                PLDirtyPeriodStatus::BLOCKED_BY_CLOSE->value,
            ],
            PLDirtyPeriodStatus::DONE->value => [PLDirtyPeriodStatus::PENDING->value],
            PLDirtyPeriodStatus::FAILED->value => [PLDirtyPeriodStatus::PENDING->value],
            PLDirtyPeriodStatus::BLOCKED_BY_CLOSE->value => [PLDirtyPeriodStatus::PENDING->value],
        ];

        foreach (PLDirtyPeriodStatus::cases() as $from) {
            foreach (PLDirtyPeriodStatus::cases() as $to) {
                yield sprintf('%s -> %s', $from->value, $to->value) => [
                    $from,
                    $to,
                    in_array($to->value, $allowed[$from->value], true),
                ];
            }
        }
    }
}
