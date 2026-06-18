<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Enum;

use App\Ingestion\Enum\SyncJobStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyncJobStatusTest extends TestCase
{
    #[DataProvider('transitionProvider')]
    public function testTransitionMatrix(SyncJobStatus $from, SyncJobStatus $to, bool $expected): void
    {
        self::assertSame($expected, $from->canTransitionTo($to));
    }

    /**
     * @return iterable<string, array{SyncJobStatus, SyncJobStatus, bool}>
     */
    public static function transitionProvider(): iterable
    {
        $allowed = [
            SyncJobStatus::OPEN->value => [SyncJobStatus::RUNNING->value, SyncJobStatus::CANCELLED->value],
            SyncJobStatus::RUNNING->value => [
                SyncJobStatus::RUNNING->value,
                SyncJobStatus::COMPLETED->value,
                SyncJobStatus::FAILED->value,
                SyncJobStatus::CANCELLED->value,
            ],
            SyncJobStatus::COMPLETED->value => [],
            SyncJobStatus::FAILED->value => [],
            SyncJobStatus::CANCELLED->value => [],
        ];

        foreach (SyncJobStatus::cases() as $from) {
            foreach (SyncJobStatus::cases() as $to) {
                yield sprintf('%s -> %s', $from->value, $to->value) => [
                    $from,
                    $to,
                    in_array($to->value, $allowed[$from->value], true),
                ];
            }
        }
    }
}
