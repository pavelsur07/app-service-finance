<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\MarketplaceAds\Entity\AdChunkProgress;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class AdChunkProgressTest extends TestCase
{
    public function testConstructorPopulatesAllFields(): void
    {
        $jobId = Uuid::uuid7()->toString();
        $dateFrom = new \DateTimeImmutable('2026-03-01');
        $dateTo = new \DateTimeImmutable('2026-03-10');

        $progress = new AdChunkProgress($jobId, $dateFrom, $dateTo);

        self::assertNotSame('', $progress->getId());
        self::assertSame($jobId, $progress->getJobId());
        self::assertSame('2026-03-01', $progress->getDateFrom()->format('Y-m-d'));
        self::assertSame('2026-03-10', $progress->getDateTo()->format('Y-m-d'));
        self::assertInstanceOf(\DateTimeImmutable::class, $progress->getCompletedAt());
    }
}
