<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Storage;

use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Infrastructure\Storage\PathSegmentNormalizer;
use App\Ingestion\Infrastructure\Storage\RawStoragePathBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class RawStoragePathBuilderTest extends TestCase
{
    public function testBuildsDeterministicGzipNdjsonPath(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $builder = new RawStoragePathBuilder(new PathSegmentNormalizer());
        $batch = new RawBatch(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'Main Shop #1',
            source: IngestSource::OZON,
            resourceType: 'seller/report',
            externalId: 'external-1',
            syncJobId: 'sync job 42',
            fetchedAt: new \DateTimeImmutable('2026-06-15 10:20:30'),
            rows: [['id' => 1]],
        );

        self::assertSame(
            sprintf('%s/ozon/Main-Shop-1/seller-report/2026/06/15/sync-job-42/external-1/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.ndjson.gz', $companyId),
            $builder->build($batch, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
        );
    }
}
