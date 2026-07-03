<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Application\Source\Ozon\StoredOzonAccrualTypeNameResolver;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Infrastructure\Storage\RawNdjsonCodec;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class StoredOzonAccrualTypeNameResolverTest extends IntegrationTestCase
{
    public function testMissingDictionaryObjectDoesNotBreakTypeResolution(): void
    {
        $companyId = Uuid::uuid7()->toString();

        /** @var RawStorageFacade $rawStorage */
        $rawStorage = self::getContainer()->get(RawStorageFacade::class);
        /** @var ObjectStorageInterface $objectStorage */
        $objectStorage = self::getContainer()->get(ObjectStorageInterface::class);
        /** @var StoredOzonAccrualTypeNameResolver $resolver */
        $resolver = self::getContainer()->get(StoredOzonAccrualTypeNameResolver::class);

        $record = $rawStorage->store(new RawBatch(
            companyId: $companyId,
            connectionRef: 'ozon-connection',
            shopRef: 'ozon-shop',
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_TYPES,
            externalId: 'accrual-types',
            syncJobId: 'sync-job-types',
            fetchedAt: new \DateTimeImmutable('2026-07-03 10:00:00'),
            rows: [['type_id' => 12, 'name' => 'Cross-docking']],
        ))[0];

        $objectStorage->delete($record->getStoragePath());

        self::assertNull($resolver->resolve($companyId, '12'));

        $payload = gzencode((new RawNdjsonCodec())->encodeRows([['type_id' => 12, 'name' => 'Cross-docking']]), 6);
        self::assertIsString($payload);
        $objectStorage->write($record->getStoragePath(), $payload);

        self::assertSame('Cross-docking', $resolver->resolve($companyId, '12'));
    }
}
