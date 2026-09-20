<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Entity;

use App\MoySklad\Entity\MoySkladSyncCursor;
use App\MoySklad\Entity\MoySkladSyncRun;
use PHPUnit\Framework\TestCase;

final class MoySkladSyncStateTest extends TestCase
{
    private const COMPANY = '11111111-1111-4111-8111-111111111111';
    private const CONNECTION = '22222222-2222-4222-8222-222222222222';

    public function testCursorOnlyRecordsCompletedFullPass(): void
    {
        $cursor = new MoySkladSyncCursor('33333333-3333-7333-8333-333333333333', self::COMPANY, self::CONNECTION, 'counterparty');
        self::assertNull($cursor->getLastCompletedAt());
        $finishedAt = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $cursor->completeAt($finishedAt);
        self::assertSame($finishedAt, $cursor->getLastCompletedAt());
        self::assertSame(self::COMPANY, $cursor->getCompanyId());
    }

    public function testRunCountsCommittedRowsAndCanSucceed(): void
    {
        $run = new MoySkladSyncRun('44444444-4444-7444-8444-444444444444', self::COMPANY, self::CONNECTION, 'counterparty', new \DateTimeImmutable('2026-09-20T09:00:00+00:00'));
        self::assertSame('running', $run->getStatus());
        $run->recordPage(2, 1, 0, 1);
        $run->recordPage(3, 0, 2, 1);
        $finishedAt = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $run->succeed($finishedAt);

        self::assertSame('succeeded', $run->getStatus());
        self::assertSame(5, $run->getProcessed());
        self::assertSame(1, $run->getCreated());
        self::assertSame(2, $run->getUpdated());
        self::assertSame(2, $run->getUnchanged());
        self::assertSame($finishedAt, $run->getFinishedAt());
        self::assertNull($run->getErrorCategory());
    }

    public function testFailedRunKeepsCountsAndSafeCategory(): void
    {
        $run = new MoySkladSyncRun('44444444-4444-7444-8444-444444444444', self::COMPANY, self::CONNECTION, 'counterparty', new \DateTimeImmutable('2026-09-20T09:00:00+00:00'));
        $run->recordPage(2, 2, 0, 0);
        $run->fail('rate_limited', new \DateTimeImmutable('2026-09-20T09:10:00+00:00'));

        self::assertSame('failed', $run->getStatus());
        self::assertSame('rate_limited', $run->getErrorCategory());
        self::assertSame(2, $run->getProcessed());
    }

    public function testRunRejectsInconsistentCounters(): void
    {
        $run = new MoySkladSyncRun('44444444-4444-7444-8444-444444444444', self::COMPANY, self::CONNECTION, 'counterparty', new \DateTimeImmutable('2026-09-20T09:00:00+00:00'));
        $this->expectException(\InvalidArgumentException::class);
        $run->recordPage(2, 1, 0, 0);
    }
}
