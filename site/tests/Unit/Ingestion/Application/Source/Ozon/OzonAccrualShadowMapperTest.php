<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Source\Ozon\OzonAccrualShadowMapper;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class OzonAccrualShadowMapperTest extends TestCase
{
    public function testRegistersOnlyAccrualResourceTypes(): void
    {
        $mapper = new OzonAccrualShadowMapper();

        self::assertSame(IngestSource::OZON, $mapper->source());
        self::assertSame([
            OzonResourceType::ACCRUAL_POSTINGS,
            OzonResourceType::ACCRUAL_TYPES,
        ], $mapper->resourceTypes());
    }

    public function testDoesNotCreateCanonicalTransactionsOrControlSums(): void
    {
        $mapper = new OzonAccrualShadowMapper();
        $rawRecord = new IngestRawRecord(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_POSTINGS,
            externalId: 'accrual-postings:2026-06-13:2026-06-19',
            storagePath: 'raw.ndjson.gz',
            hash: str_repeat('a', 64),
            byteSize: 10,
            fetchedAt: new \DateTimeImmutable('2026-06-20 10:00:00'),
            syncJobId: Uuid::uuid7()->toString(),
        );

        self::assertSame([], $mapper->map($rawRecord, [['posting_number' => 'posting-1']]));
        self::assertSame([], $mapper->controlSum([['posting_number' => 'posting-1']]));
    }
}
