<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Entity;

use App\Ingestion\Entity\IngestCursor;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class IngestCursorTest extends TestCase
{
    public function testConstructorSetsDefaults(): void
    {
        $companyId = Uuid::uuid7()->toString();

        $cursor = new IngestCursor($companyId, 'connection-1', 'ozon_seller_daily_report');

        self::assertTrue(Uuid::isValid($cursor->getId()));
        self::assertSame($companyId, $cursor->getCompanyId());
        self::assertSame('connection-1', $cursor->getConnectionRef());
        self::assertSame('ozon_seller_daily_report', $cursor->getResourceType());
        self::assertSame('', $cursor->getShopRef());
        self::assertSame('', $cursor->getCursorValue());
        self::assertNull($cursor->getLastFetchedAt());
        self::assertNull($cursor->getLastSyncJobId());
    }

    public function testAdvanceUpdatesCursorAndTimestamps(): void
    {
        $cursor = new IngestCursor(Uuid::uuid7()->toString(), 'connection-1', 'resource-1', 'shop-1');
        $syncJobId = Uuid::uuid7()->toString();
        $fetchedAt = new \DateTimeImmutable('2026-06-18 10:15:00');
        $originalUpdatedAt = $cursor->getUpdatedAt();

        usleep(1000);
        $cursor->advance('next-page-token', $syncJobId, $fetchedAt);

        self::assertSame('next-page-token', $cursor->getCursorValue());
        self::assertSame($syncJobId, $cursor->getLastSyncJobId());
        self::assertSame($fetchedAt, $cursor->getLastFetchedAt());
        self::assertGreaterThan($originalUpdatedAt, $cursor->getUpdatedAt());
    }

    public function testAdvanceRejectsEmptyCursorValue(): void
    {
        $cursor = new IngestCursor(Uuid::uuid7()->toString(), 'connection-1', 'resource-1');

        $this->expectException(\InvalidArgumentException::class);

        $cursor->advance('', Uuid::uuid7()->toString());
    }
}
