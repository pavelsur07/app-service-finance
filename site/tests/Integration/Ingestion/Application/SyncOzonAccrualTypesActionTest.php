<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\SyncOzonAccrualTypesAction;
use App\Ingestion\Application\DTO\OzonAccrualTypesSyncResult;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\NotOzonAccrualTypesRawRecordException;
use App\Ingestion\Exception\RawRecordNotFoundException;
use App\Ingestion\Facade\RawStorageFacade;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class SyncOzonAccrualTypesActionTest extends IntegrationTestCase
{
    private const FIXTURE = __DIR__.'/../../../Fixtures/Marketplace/Ozon/accrual_types.json';

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = Uuid::uuid7()->toString();
    }

    public function testFixtureVersionFillsDictionary(): void
    {
        $raw = $this->storeVersion($this->fixtureRows(), '2026-10-08 02:30:00');

        $result = $this->sync($raw);

        self::assertSame(128, $result->rows);
        self::assertCount(128, $result->createdTypeIds);
        self::assertSame([], $result->updatedTypeIds);
        self::assertFalse($result->stale);

        $row = $this->typeRow(29);
        self::assertSame('LastMileCourier', $row['name']);
        self::assertSame('Доставка до места выдачи', $row['description']);
        self::assertNull($this->typeRow(127)['description']);
        self::assertSame(128, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ingest_ozon_accrual_types'));
    }

    public function testSameContentAgainChangesNothing(): void
    {
        $this->sync($this->storeVersion($this->fixtureRows(), '2026-10-08 02:30:00'));
        $otherCompany = Uuid::uuid7()->toString();

        $result = $this->sync($this->storeVersion($this->fixtureRows(), '2026-10-08 02:31:00', $otherCompany), $otherCompany);

        self::assertFalse($result->hasChanges());
        self::assertSame(128, $result->unchanged);
    }

    public function testChangedDescriptionUpdatesRowButKeepsNameAndFirstSeen(): void
    {
        $this->sync($this->storeVersion([['id' => 29, 'name' => 'LastMileCourier', 'description' => '']], '2026-10-01 02:30:00'));

        $result = $this->sync($this->storeVersion([['id' => 29, 'name' => 'LastMileCourier', 'description' => 'Доставка до места выдачи партнерами']], '2026-10-08 02:30:00'));

        self::assertSame([29], $result->updatedTypeIds);
        $row = $this->typeRow(29);
        self::assertSame('LastMileCourier', $row['name']);
        self::assertSame('Доставка до места выдачи партнерами', $row['description']);
        self::assertStringStartsWith('2026-10-01 02:30:00', (string) $row['first_seen_at']);
    }

    public function testMissingTypeIsMarkedRemovedAndRestoredLater(): void
    {
        $both = [['id' => 1, 'name' => 'Acquiring', 'description' => 'Эквайринг'], ['id' => 2, 'name' => 'BackwardShipment', 'description' => 'Обратная магистраль']];
        $this->sync($this->storeVersion($both, '2026-10-01 02:30:00'));

        $removed = $this->sync($this->storeVersion([$both[0]], '2026-10-08 02:30:00'));

        self::assertSame([2], $removed->removedTypeIds);
        self::assertNotNull($this->typeRow(2)['removed_at']);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ingest_ozon_accrual_types'));

        $otherCompany = Uuid::uuid7()->toString();
        $restored = $this->sync($this->storeVersion($both, '2026-10-15 02:30:00', $otherCompany), $otherCompany);

        self::assertSame([2], $restored->updatedTypeIds);
        self::assertNull($this->typeRow(2)['removed_at']);
    }

    /**
     * A → B → A у одной компании: вернувшаяся версия A дедуплицируется в прежнюю
     * сырую запись, но применяется как самая новая — тип восстанавливается.
     */
    public function testReturningVersionOfSameCompanyIsAppliedAsNewest(): void
    {
        $both = [['id' => 1, 'name' => 'Acquiring', 'description' => 'Эквайринг'], ['id' => 2, 'name' => 'BackwardShipment', 'description' => 'Обратная магистраль']];
        $versionA = $this->storeVersion($both, '2026-10-01 02:30:00');
        $this->sync($versionA);
        $this->sync($this->storeVersion([$both[0]], '2026-10-08 02:30:00'));
        self::assertNotNull($this->typeRow(2)['removed_at']);

        $again = $this->storeVersion($both, '2026-10-15 02:30:00');
        self::assertSame($versionA->getId(), $again->getId(), 'same content is deduplicated');
        $restored = $this->sync($again);

        self::assertFalse($restored->stale);
        self::assertNull($this->typeRow(2)['removed_at']);
    }

    /**
     * Команда применяет версии одним EntityManager без clear(): пока она держит
     * тип в UnitOfWork, другой воркер помечает его удалённым. Новая версия с тем
     * же содержимым обязана восстановить тип, а не довериться кэшу.
     */
    public function testWriteOfAnotherWorkerIsNotHiddenByCachedEntities(): void
    {
        $both = [['id' => 1, 'name' => 'Acquiring', 'description' => 'Эквайринг'], ['id' => 2, 'name' => 'BackwardShipment', 'description' => 'Обратная магистраль']];
        $this->sync($this->storeVersion($both, '2026-10-01 02:30:00'), clear: false);

        // Другой воркер применил версию без типа 2 (своим EntityManager).
        $this->connection->executeStatement(
            "UPDATE ingest_ozon_accrual_types SET removed_at = '2026-10-08 02:30:00', version_fetched_at = '2026-10-08 02:30:00' WHERE type_id = 2",
        );

        $restored = $this->sync($this->storeVersion($both, '2026-10-15 02:30:00'), clear: false);

        self::assertSame([2], $restored->updatedTypeIds);
        self::assertNull($this->typeRow(2)['removed_at']);
    }

    public function testOlderVersionOnlyAddsUnknownTypes(): void
    {
        $this->sync($this->storeVersion([['id' => 1, 'name' => 'Acquiring', 'description' => 'Эквайринг']], '2026-10-08 02:30:00'));

        $result = $this->sync($this->storeVersion([
            ['id' => 1, 'name' => 'AcquiringOld', 'description' => 'Старое название'],
            ['id' => 3, 'name' => 'BrandCommission', 'description' => 'Продвижение бренда'],
        ], '2026-10-01 02:30:00'));

        self::assertTrue($result->stale);
        self::assertSame([3], $result->createdTypeIds);
        self::assertSame([], $result->updatedTypeIds);
        self::assertSame([], $result->removedTypeIds);
        self::assertSame('Acquiring', $this->typeRow(1)['name']);
    }

    public function testOlderVersionAddsUnknownTypeAsRemoved(): void
    {
        $this->sync($this->storeVersion([['id' => 1, 'name' => 'Acquiring', 'description' => 'Эквайринг']], '2026-10-08 02:30:00'));

        $this->sync($this->storeVersion([
            ['id' => 1, 'name' => 'Acquiring', 'description' => 'Эквайринг'],
            ['id' => 3, 'name' => 'BrandCommission', 'description' => 'Продвижение бренда'],
        ], '2026-10-01 02:30:00'));

        self::assertStringStartsWith('2026-10-08 02:30:00', (string) $this->typeRow(3)['removed_at']);
    }

    public function testPartiallyParsedVersionDoesNotMarkTypesRemoved(): void
    {
        $both = [['id' => 1, 'name' => 'Acquiring', 'description' => 'Эквайринг'], ['id' => 2, 'name' => 'BackwardShipment', 'description' => 'Обратная магистраль']];
        $this->sync($this->storeVersion($both, '2026-10-01 02:30:00'));

        $result = $this->sync($this->storeVersion([$both[0], ['id' => 2, 'name' => '']], '2026-10-08 02:30:00'));

        self::assertSame(1, $result->skippedRows);
        self::assertSame([], $result->removedTypeIds);
        self::assertNull($this->typeRow(2)['removed_at']);
    }

    public function testVersionWithoutValidRowsLeavesDictionaryIntact(): void
    {
        $this->sync($this->storeVersion([['id' => 1, 'name' => 'Acquiring', 'description' => 'Эквайринг']], '2026-10-01 02:30:00'));

        $result = $this->sync($this->storeVersion([['id' => 'x', 'name' => ''], ['name' => 'NoId']], '2026-10-08 02:30:00'));

        self::assertTrue($result->empty);
        self::assertSame(2, $result->skippedRows);
        self::assertNull($this->typeRow(1)['removed_at']);
    }

    public function testDryRunWritesNothing(): void
    {
        $result = $this->sync($this->storeVersion($this->fixtureRows(), '2026-10-08 02:30:00'), dryRun: true);

        self::assertTrue($result->dryRun);
        self::assertCount(128, $result->createdTypeIds);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ingest_ozon_accrual_types'));
    }

    public function testRejectsRawRecordOfAnotherResource(): void
    {
        /** @var RawStorageFacade $storage */
        $storage = self::getContainer()->get(RawStorageFacade::class);
        $raw = $storage->store(new RawBatch(
            companyId: $this->companyId,
            connectionRef: $this->companyId,
            shopRef: $this->companyId,
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            externalId: 'accrual-by-day:2026-10-01:2026-10-01',
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: new \DateTimeImmutable('2026-10-02 02:30:00'),
            rows: [['accrual_id' => 1]],
        ))[0];

        $this->expectException(NotOzonAccrualTypesRawRecordException::class);

        $this->sync($raw);
    }

    public function testRawRecordOfAnotherCompanyIsNotFound(): void
    {
        $raw = $this->storeVersion($this->fixtureRows(), '2026-10-08 02:30:00');

        $this->expectException(RawRecordNotFoundException::class);

        $this->sync($raw, Uuid::uuid7()->toString());
    }

    private function sync(IngestRawRecord $raw, ?string $companyId = null, bool $dryRun = false, bool $clear = true): OzonAccrualTypesSyncResult
    {
        /** @var SyncOzonAccrualTypesAction $action */
        $action = self::getContainer()->get(SyncOzonAccrualTypesAction::class);
        $result = $action($companyId ?? $raw->getCompanyId(), $raw->getId(), $dryRun);
        if ($clear) {
            $this->em->clear();
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function storeVersion(array $rows, string $fetchedAt, ?string $companyId = null): IngestRawRecord
    {
        $companyId ??= $this->companyId;

        /** @var RawStorageFacade $storage */
        $storage = self::getContainer()->get(RawStorageFacade::class);

        $record = $storage->store(new RawBatch(
            companyId: $companyId,
            connectionRef: $companyId,
            shopRef: $companyId,
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_TYPES,
            externalId: 'accrual-types',
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: new \DateTimeImmutable($fetchedAt),
            rows: $rows,
        ))[0];

        // Время версии — last_seen_at: в проде это момент получения от Ozon,
        // в тесте — указанное время (store() ставит «сейчас»).
        $this->connection->executeStatement(
            'UPDATE ingest_raw_records SET last_seen_at = :seenAt WHERE id = :id',
            ['seenAt' => $fetchedAt, 'id' => $record->getId()],
        );
        $this->em->refresh($record);

        return $record;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureRows(): array
    {
        /** @var array{accrual_types: list<array<string, mixed>>} $fixture */
        $fixture = json_decode((string) file_get_contents(self::FIXTURE), true, flags: \JSON_THROW_ON_ERROR);

        return $fixture['accrual_types'];
    }

    /**
     * @return array<string, mixed>
     */
    private function typeRow(int $typeId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT type_id, name, description, first_seen_at, removed_at FROM ingest_ozon_accrual_types WHERE type_id = :typeId',
            ['typeId' => $typeId],
        );
        self::assertIsArray($row, sprintf('type %d is missing', $typeId));

        return $row;
    }
}
