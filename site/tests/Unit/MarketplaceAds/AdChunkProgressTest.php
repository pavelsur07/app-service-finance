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

        self::assertTrue(Uuid::isValid($progress->getId()));
        self::assertSame($jobId, $progress->getJobId());
        self::assertSame('2026-03-01', $progress->getDateFrom()->format('Y-m-d'));
        self::assertSame('2026-03-10', $progress->getDateTo()->format('Y-m-d'));
        self::assertInstanceOf(\DateTimeImmutable::class, $progress->getCompletedAt());
    }

    public function testConstructorNormalizesDatesToMidnight(): void
    {
        // Без нормализации два чанка с одинаковыми календарными датами, но
        // разным временем дня, считались бы разными на уровне UNIQUE
        // (job_id, date_from, date_to) — идемпотентность повторной обработки
        // чанка сломалась бы. Проверяем, что конструктор всегда приводит
        // к началу суток.
        $progress = new AdChunkProgress(
            Uuid::uuid7()->toString(),
            new \DateTimeImmutable('2026-03-01 15:30:45'),
            new \DateTimeImmutable('2026-03-10 22:45:12'),
        );

        self::assertSame('00:00:00', $progress->getDateFrom()->format('H:i:s'));
        self::assertSame('00:00:00', $progress->getDateTo()->format('H:i:s'));
        self::assertSame('2026-03-01', $progress->getDateFrom()->format('Y-m-d'));
        self::assertSame('2026-03-10', $progress->getDateTo()->format('Y-m-d'));
    }

    public function testConstructorRejectsInvertedRange(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('dateFrom не может быть позже dateTo.');

        new AdChunkProgress(
            Uuid::uuid7()->toString(),
            new \DateTimeImmutable('2026-03-10'),
            new \DateTimeImmutable('2026-03-01'),
        );
    }
}
