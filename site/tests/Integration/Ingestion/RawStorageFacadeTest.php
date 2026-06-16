<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\RawRecordNotFoundException;
use App\Ingestion\Facade\RawStorageFacade;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class RawStorageFacadeTest extends IntegrationTestCase
{
    public function testStoreWritesObjectAndMetadataThenReadReturnsRows(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $rows = [
            ['sku' => 'SKU-1', 'qty' => 2],
            ['sku' => 'SKU-2', 'qty' => 0],
        ];

        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);
        /** @var ObjectStorageInterface $storage */
        $storage = self::getContainer()->get(ObjectStorageInterface::class);

        $records = $facade->store($this->batch($companyId, rows: $rows));

        self::assertCount(1, $records);
        $record = $records[0];
        self::assertSame($companyId, $record->getCompanyId());
        self::assertSame('shop-main', $record->getShopRef());
        self::assertTrue($storage->exists($record->getStoragePath()));

        $row = $this->connection->fetchAssociative(
            'SELECT company_id, storage_path, hash, byte_size, normalization_status FROM ingest_raw_records WHERE id = :id',
            ['id' => $record->getId()],
        );

        self::assertIsArray($row);
        self::assertSame($companyId, $row['company_id']);
        self::assertSame($record->getStoragePath(), $row['storage_path']);
        self::assertSame($record->getHash(), $row['hash']);
        self::assertSame($record->getByteSize(), (int) $row['byte_size']);
        self::assertSame('pending', $row['normalization_status']);
        self::assertEquals($rows, iterator_to_array($facade->read($record->getId(), $companyId)));
    }

    public function testStoreDuplicateHashDoesNotCreateNewObjectAndUpdatesLastSeenAt(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $rows = [['sku' => 'SKU-1', 'qty' => 2]];

        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);
        /** @var ObjectStorageInterface $storage */
        $storage = self::getContainer()->get(ObjectStorageInterface::class);

        $first = $facade->store($this->batch($companyId, syncJobId: 'sync-job-first', rows: $rows))[0];
        $firstPath = $first->getStoragePath();
        $firstLastSeenAt = $first->getLastSeenAt();

        usleep(1000);

        $second = $facade->store($this->batch($companyId, syncJobId: 'sync-job-second', rows: $rows))[0];

        self::assertSame($first->getId(), $second->getId());
        self::assertSame($firstPath, $second->getStoragePath());
        self::assertGreaterThan($firstLastSeenAt, $second->getLastSeenAt());
        self::assertTrue($storage->exists($firstPath));
        self::assertFalse($storage->exists(sprintf(
            '%s/ozon/shop-main/seller-report/2026/06/15/sync-job-second/external-report-1/%s.ndjson.gz',
            $companyId,
            $first->getHash(),
        )));

        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_raw_records WHERE company_id = :company_id AND external_id = :external_id',
            ['company_id' => $companyId, 'external_id' => 'external-report-1'],
        ));
    }

    public function testTenantCannotReadAnotherCompanyRawRecord(): void
    {
        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();

        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);

        $record = $facade->store($this->batch($companyB))[0];

        $this->expectException(RawRecordNotFoundException::class);
        iterator_to_array($facade->read($record->getId(), $companyA));
    }

    public function testLargePayloadIsStoredOnlyInObjectStorage(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $largeValue = str_repeat('x', 1024 * 1024);

        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);
        /** @var ObjectStorageInterface $storage */
        $storage = self::getContainer()->get(ObjectStorageInterface::class);

        $record = $facade->store($this->batch(
            companyId: $companyId,
            rows: [['sku' => 'SKU-LARGE', 'payload' => $largeValue]],
        ))[0];

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ingest_raw_records WHERE id = :id',
            ['id' => $record->getId()],
        );

        self::assertIsArray($row);
        self::assertArrayNotHasKey('payload', $row);
        self::assertStringNotContainsString('SKU-LARGE', json_encode($row, JSON_THROW_ON_ERROR));
        self::assertTrue($storage->exists($record->getStoragePath()));
        self::assertGreaterThan(0, $record->getByteSize());
    }

    public function testMultipleBatchesInSameSyncJobUseDistinctObjectPaths(): void
    {
        $companyId = Uuid::uuid7()->toString();

        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);

        $firstRows = [['sku' => 'SKU-1', 'qty' => 1]];
        $secondRows = [['sku' => 'SKU-2', 'qty' => 2]];

        $first = $facade->store($this->batch(
            companyId: $companyId,
            syncJobId: 'sync-job-shared',
            externalId: 'external-report-1',
            rows: $firstRows,
        ))[0];
        $second = $facade->store($this->batch(
            companyId: $companyId,
            syncJobId: 'sync-job-shared',
            externalId: 'external-report-2',
            rows: $secondRows,
        ))[0];

        self::assertNotSame($first->getStoragePath(), $second->getStoragePath());
        self::assertStringContainsString('/external-report-1/', $first->getStoragePath());
        self::assertStringContainsString('/external-report-2/', $second->getStoragePath());
        self::assertStringContainsString($first->getHash(), $first->getStoragePath());
        self::assertStringContainsString($second->getHash(), $second->getStoragePath());
        self::assertEquals($firstRows, iterator_to_array($facade->read($first->getId(), $companyId)));
        self::assertEquals($secondRows, iterator_to_array($facade->read($second->getId(), $companyId)));
    }

    public function testSameExternalIdAndHashAcrossResourceTypesCreatesSeparateRecords(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $rows = [['sku' => 'SKU-SHARED', 'qty' => 1]];

        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);

        $sellerReport = $facade->store($this->batch(
            companyId: $companyId,
            syncJobId: 'sync-job-seller',
            externalId: 'external-shared',
            resourceType: 'seller-report',
            rows: $rows,
        ))[0];
        $analyticsReport = $facade->store($this->batch(
            companyId: $companyId,
            syncJobId: 'sync-job-analytics',
            externalId: 'external-shared',
            resourceType: 'analytics-report',
            rows: $rows,
        ))[0];

        self::assertNotSame($sellerReport->getId(), $analyticsReport->getId());
        self::assertSame($sellerReport->getHash(), $analyticsReport->getHash());
        self::assertStringContainsString('/seller-report/', $sellerReport->getStoragePath());
        self::assertStringContainsString('/analytics-report/', $analyticsReport->getStoragePath());
        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_raw_records WHERE company_id = :company_id AND external_id = :external_id',
            ['company_id' => $companyId, 'external_id' => 'external-shared'],
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function batch(
        string $companyId,
        string $syncJobId = 'sync-job-1',
        string $externalId = 'external-report-1',
        string $resourceType = 'seller-report',
        array $rows = [['sku' => 'SKU-1']],
    ): RawBatch {
        return new RawBatch(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-main',
            source: IngestSource::OZON,
            resourceType: $resourceType,
            externalId: $externalId,
            syncJobId: $syncJobId,
            fetchedAt: new \DateTimeImmutable('2026-06-15 10:20:30'),
            rows: $rows,
        );
    }
}
