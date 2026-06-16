<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application;

use App\Ingestion\Application\StoreRawBatchAction;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Infrastructure\Storage\PathSegmentNormalizer;
use App\Ingestion\Infrastructure\Storage\RawNdjsonCodec;
use App\Ingestion\Infrastructure\Storage\RawStoragePathBuilder;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\StoredObject;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class StoreRawBatchActionTest extends TestCase
{
    public function testConcurrentDuplicateInsertReturnsExistingRecordAfterUniqueViolation(): void
    {
        $companyId = '11111111-1111-7111-8111-111111111111';
        $resourceType = 'seller-report';
        $externalId = 'external-report-1';
        $rows = [['sku' => 'SKU-1', 'qty' => 1]];
        $batch = new RawBatch(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-main',
            source: IngestSource::OZON,
            resourceType: $resourceType,
            externalId: $externalId,
            syncJobId: 'sync-job-1',
            fetchedAt: new \DateTimeImmutable('2026-06-15 10:20:30'),
            rows: $rows,
        );
        $codec = new RawNdjsonCodec();
        $hash = hash('sha256', $codec->encodeRows($rows));
        $existingRecord = new IngestRawRecord(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-main',
            source: IngestSource::OZON,
            resourceType: $resourceType,
            externalId: $externalId,
            storagePath: 'existing-path.ndjson.gz',
            hash: $hash,
            byteSize: 42,
            fetchedAt: new \DateTimeImmutable('2026-06-15 10:20:30'),
            syncJobId: 'sync-job-previous',
        );
        $originalLastSeenAt = $existingRecord->getLastSeenAt();

        $repository = $this->createMock(IngestRawRecordRepository::class);
        $repository->expects(self::once())
            ->method('findLatestByCompanySourceExternalId')
            ->with($companyId, IngestSource::OZON, $resourceType, $externalId)
            ->willReturn(null);

        $duplicateLookupCalls = 0;
        $repository->expects(self::exactly(2))
            ->method('findOneByCompanySourceExternalIdAndHash')
            ->willReturnCallback(
                function (
                    string $actualCompanyId,
                    IngestSource $actualSource,
                    string $actualResourceType,
                    string $actualExternalId,
                    string $actualHash,
                ) use (
                    &$duplicateLookupCalls,
                    $companyId,
                    $resourceType,
                    $externalId,
                    $hash,
                    $existingRecord,
                ): ?IngestRawRecord {
                    ++$duplicateLookupCalls;

                    self::assertSame($companyId, $actualCompanyId);
                    self::assertSame(IngestSource::OZON, $actualSource);
                    self::assertSame($resourceType, $actualResourceType);
                    self::assertSame($externalId, $actualExternalId);
                    self::assertSame($hash, $actualHash);

                    return 1 === $duplicateLookupCalls ? null : $existingRecord;
                },
            );

        $objectStorage = $this->createMock(ObjectStorageInterface::class);
        $objectStorage->expects(self::once())
            ->method('write')
            ->with(
                self::callback(static fn (string $path): bool => str_contains($path, '/seller-report/')
                    && str_contains($path, '/external-report-1/')),
                self::callback(static fn (string $payload): bool => $rows == array_map(
                    static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                    array_filter(explode("\n", trim((string) gzdecode($payload)))),
                )),
            )
            ->willReturnCallback(static fn (string $path, string $payload): StoredObject => new StoredObject($path, strlen($payload)));

        $uniqueViolation = new UniqueConstraintViolationException(
            new class('Duplicate raw record') extends \Exception implements DriverException {
                public function getSQLState()
                {
                    return '23505';
                }
            },
            null,
        );

        $flushCalls = 0;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(IngestRawRecord::class));
        $entityManager->expects(self::never())->method('clear');
        $entityManager->expects(self::once())
            ->method('flush')
            ->willReturnCallback(static function () use (&$flushCalls, $uniqueViolation): void {
                ++$flushCalls;

                if (1 === $flushCalls) {
                    throw $uniqueViolation;
                }
            });
        $entityManager->expects(self::once())->method('isOpen')->willReturn(false);

        $recoveredEntityManager = $this->createMock(EntityManagerInterface::class);
        $recoveredEntityManager->expects(self::once())
            ->method('getRepository')
            ->with(IngestRawRecord::class)
            ->willReturn($repository);
        $recoveredEntityManager->expects(self::once())->method('flush');

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects(self::once())
            ->method('resetManager')
            ->willReturn($recoveredEntityManager);

        $action = new StoreRawBatchAction(
            $repository,
            $objectStorage,
            $codec,
            new RawStoragePathBuilder(new PathSegmentNormalizer()),
            $entityManager,
            $managerRegistry,
        );

        $records = $action($batch);

        self::assertSame([$existingRecord], $records);
        self::assertGreaterThan($originalLastSeenAt, $existingRecord->getLastSeenAt());
    }
}
