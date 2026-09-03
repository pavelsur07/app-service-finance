<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application;

use App\Ingestion\Application\StoreRawBatchAction;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\RawRecordNotFoundException;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StoreRawBatchActionTest extends TestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function lockedRowProvider(): iterable
    {
        yield 'строка на месте' => [true];
        yield 'строка исчезла' => [false];
    }

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
            rows: (static function () use ($rows): \Generator {
                yield from $rows;
            })(),
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

        // Порядок, а не только факт: блокировка обязана быть ВЗЯТА до
        // обращения к хранилищу. Иначе восстановление писало бы объект и
        // снимало отметки, пока retention удаляет тот же объект.
        $calls = [];

        $repository->expects(self::once())
            ->method('findOneForUpdate')
            ->with($companyId, $existingRecord->getId())
            ->willReturnCallback(static function () use (&$calls, $existingRecord): IngestRawRecord {
                $calls[] = 'lock';

                return $existingRecord;
            });

        $objectStorage = $this->createMock(ObjectStorageInterface::class);
        $objectStorage->expects(self::once())
            ->method('exists')
            ->with('existing-path.ndjson.gz')
            ->willReturnCallback(static function () use (&$calls): bool {
                $calls[] = 'storage';

                return true;
            });
        $objectStorage->expects(self::once())
            ->method('write')
            ->with(
                self::callback(static fn (string $path): bool => str_contains($path, '/seller-report/')
                    && str_contains($path, '/external-report-1/')),
                self::callback(static fn (string $payload): bool => $rows == array_map(
                    static fn (string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR),
                    array_filter(explode("\n", trim((string) gzdecode($payload)))),
                )),
            )
            ->willReturnCallback(static fn (string $path, string $payload): StoredObject => new StoredObject($path, strlen($payload)));

        $uniqueViolation = new UniqueConstraintViolationException(
            new class('Duplicate raw record') extends \Exception implements DriverException {
                public function getSQLState(): string
                {
                    return '23505';
                }
            },
            null,
        );

        $flushCalls = 0;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        // Повторная встреча сырья идёт под блокировкой строки, то есть внутри
        // транзакции: без этого замыкание не выполнилось бы вовсе.
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $work): mixed => $work($entityManager),
        );
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
        // Восстановление идёт под блокировкой строки — то есть внутри
        // транзакции: без этого замыкание не выполнилось бы вовсе. Отдельного
        // flush() больше нет, коммит делает wrapInTransaction().
        $recoveredEntityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $work): mixed => $work($recoveredEntityManager),
        );
        $recoveredEntityManager->expects(self::never())->method('flush');

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
        self::assertSame(['lock', 'storage'], $calls, 'К хранилищу нельзя идти раньше блокировки строки.');
    }

    /**
     * Восстановление нагрузки — ТОЛЬКО под блокировкой существующей строки.
     *
     * Два случая одного правила, поэтому и тест один. Строка на месте — объект
     * возвращается. Строки нет — Action обязан остановиться ДО хранилища:
     * прежний откат на прочитанную ранее сущность выглядел безобидно, а был
     * тем же классом тихой потери, что и удаление строки вместе с объектом:
     * объект писался без блокировки, UPDATE задевал ноль строк, и вызывающий
     * получал «сохранено» при записи, которой нет, — а в хранилище оставалась
     * сирота.
     *
     * @param bool $rowSurvives нашлась ли строка под блокировкой
     */
    #[DataProvider('lockedRowProvider')]
    public function testSameHashLatestRecordRepairsMissingStorageObject(bool $rowSurvives): void
    {
        $companyId = '11111111-1111-7111-8111-111111111111';
        $resourceType = 'ozon_finance_accrual_types';
        $externalId = 'accrual-types';
        $rows = [['type_id' => 12, 'name' => 'Cross-docking']];
        $batch = new RawBatch(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-main',
            source: IngestSource::OZON,
            resourceType: $resourceType,
            externalId: $externalId,
            syncJobId: 'sync-job-1',
            fetchedAt: new \DateTimeImmutable('2026-07-03 10:20:30'),
            rows: $rows,
        );
        $codec = new RawNdjsonCodec();
        $existingRecord = new IngestRawRecord(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-main',
            source: IngestSource::OZON,
            resourceType: $resourceType,
            externalId: $externalId,
            storagePath: 'missing-types.ndjson.gz',
            hash: hash('sha256', $codec->encodeRows($rows)),
            byteSize: 42,
            fetchedAt: new \DateTimeImmutable('2026-06-25 10:20:30'),
            syncJobId: 'sync-job-previous',
        );

        $repository = $this->createMock(IngestRawRecordRepository::class);
        $repository->expects(self::once())
            ->method('findLatestByCompanySourceExternalId')
            ->with($companyId, IngestSource::OZON, $resourceType, $externalId)
            ->willReturn($existingRecord);
        $repository->expects(self::never())->method('findOneByCompanySourceExternalIdAndHash');
        // Блокировка строки — предусловие восстановления: без неё Action
        // отказывается идти в хранилище.
        $repository->expects(self::once())
            ->method('findOneForUpdate')
            ->with($companyId, $existingRecord->getId())
            ->willReturn($rowSurvives ? $existingRecord : null);

        $objectStorage = $this->createMock(ObjectStorageInterface::class);
        $objectStorage->expects($rowSurvives ? self::once() : self::never())
            ->method('exists')
            ->with('missing-types.ndjson.gz')
            ->willReturn(false);
        $objectStorage->expects($rowSurvives ? self::once() : self::never())
            ->method('write')
            ->with(
                'missing-types.ndjson.gz',
                self::callback(static fn (string $payload): bool => $rows == array_map(
                    static fn (string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR),
                    array_filter(explode("\n", trim((string) gzdecode($payload)))),
                )),
            )
            ->willReturnCallback(static fn (string $path, string $payload): StoredObject => new StoredObject($path, strlen($payload)));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        // Повторная встреча сырья идёт под блокировкой строки, то есть внутри
        // транзакции: без этого замыкание не выполнилось бы вовсе.
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $work): mixed => $work($entityManager),
        );
        $entityManager->expects(self::never())->method('persist');
        // Отдельного flush() больше нет: повторная встреча идёт внутри
        // wrapInTransaction(), который коммитит и сбрасывает сам.
        $entityManager->expects(self::never())->method('flush');

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects(self::never())->method('resetManager');

        $action = new StoreRawBatchAction(
            $repository,
            $objectStorage,
            $codec,
            new RawStoragePathBuilder(new PathSegmentNormalizer()),
            $entityManager,
            $managerRegistry,
        );

        if (!$rowSurvives) {
            $this->expectException(RawRecordNotFoundException::class);
        }

        self::assertSame([$existingRecord], $action($batch));
    }
}
