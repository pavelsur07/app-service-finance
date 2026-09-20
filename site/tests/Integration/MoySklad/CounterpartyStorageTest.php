<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Application\Action\ManageMoySkladConnectionAction;
use App\MoySklad\Application\Command\ManageConnectionCommand;
use App\MoySklad\Domain\CounterpartySnapshot;
use App\MoySklad\Entity\MoySkladCounterparty;
use App\MoySklad\Entity\MoySkladSyncCursor;
use App\MoySklad\Entity\MoySkladSyncRun;
use App\MoySklad\Exception\ConnectionOperationException;
use App\MoySklad\Infrastructure\Repository\MoySkladCounterpartyRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncCursorRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncRunRepository;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class CounterpartyStorageTest extends WebTestCaseBase
{
    public function testPersistsCounterpartyCursorAndRunWithoutDeletingConnection(): void
    {
        $this->resetDb();
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $now = new \DateTimeImmutable('2026-09-20T09:00:00+00:00');
        $snapshot = new CounterpartySnapshot(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'ООО Тест',
            'legal',
            null,
            null,
            null,
            null,
            null,
            null,
            false,
            $now,
        );
        $counterparty = new MoySkladCounterparty('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $connection->getCompanyId(), $connection->getId(), $snapshot, $now);
        $cursor = new MoySkladSyncCursor('cccccccc-cccc-7ccc-8ccc-cccccccccccc', $connection->getCompanyId(), $connection->getId(), 'counterparty');
        $run = new MoySkladSyncRun('dddddddd-dddd-7ddd-8ddd-dddddddddddd', $connection->getCompanyId(), $connection->getId(), 'counterparty', $now);

        $this->em()->persist($connection);
        $this->em()->persist($counterparty);
        $this->em()->persist($cursor);
        $this->em()->persist($run);
        $this->em()->flush();
        $this->em()->clear();

        $db = $this->em()->getConnection();
        self::assertSame('ООО Тест', $db->fetchOne('SELECT name FROM moysklad_counterparties WHERE id = ?', [$counterparty->getId()]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_sync_cursors WHERE connection_id = ?', [$connection->getId()]));
        self::assertSame('running', $db->fetchOne('SELECT status FROM moysklad_sync_runs WHERE id = ?', [$run->getId()]));
    }

    public function testRepositoriesRequireMatchingCompanyAndConnection(): void
    {
        $this->resetDb();
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $otherCompany = '99999999-9999-4999-8999-999999999999';
        $now = new \DateTimeImmutable('2026-09-20T09:00:00+00:00');
        $snapshot = new CounterpartySnapshot('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Тест', 'individual', null, null, null, null, null, null, false, $now);
        $record = new MoySkladCounterparty('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $connection->getCompanyId(), $connection->getId(), $snapshot, $now);
        $cursor = new MoySkladSyncCursor('cccccccc-cccc-7ccc-8ccc-cccccccccccc', $connection->getCompanyId(), $connection->getId(), 'counterparty');
        $run = new MoySkladSyncRun('dddddddd-dddd-7ddd-8ddd-dddddddddddd', $connection->getCompanyId(), $connection->getId(), 'counterparty', $now);
        foreach ([$connection, $record, $cursor, $run] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->em()->clear();

        $records = static::getContainer()->get(MoySkladCounterpartyRepository::class);
        $cursors = static::getContainer()->get(MoySkladSyncCursorRepository::class);
        $runs = static::getContainer()->get(MoySkladSyncRunRepository::class);
        self::assertNotNull($records->findByExternalId($connection->getCompanyId(), $connection->getId(), $snapshot->externalId));
        self::assertNull($records->findByExternalId($otherCompany, $connection->getId(), $snapshot->externalId));
        self::assertCount(1, $records->findByExternalIds($connection->getCompanyId(), $connection->getId(), [$snapshot->externalId]));
        self::assertSame([], $records->findByExternalIds($otherCompany, $connection->getId(), [$snapshot->externalId]));
        self::assertNull($records->findByIdAndCompanyId($record->getId(), $otherCompany));
        self::assertNotNull($records->findByIdAndCompanyId(strtoupper($record->getId()), $connection->getCompanyId()));
        self::assertNotNull($cursors->findFor($connection->getCompanyId(), $connection->getId(), 'counterparty'));
        self::assertNull($cursors->findFor($otherCompany, $connection->getId(), 'counterparty'));
        self::assertNotNull($runs->latestFor($connection->getCompanyId(), $connection->getId(), 'counterparty'));
        self::assertNull($runs->latestFor($otherCompany, $connection->getId(), 'counterparty'));
    }

    public function testConnectionWithImportedDataCannotBeDeleted(): void
    {
        $this->resetDb();
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $connection->setIsActive(false);
        $now = new \DateTimeImmutable('2026-09-20T09:00:00+00:00');
        $snapshot = new CounterpartySnapshot('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Тест', 'individual', null, null, null, null, null, null, false, $now);
        $this->em()->persist($connection);
        $this->em()->persist(new MoySkladCounterparty('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $connection->getCompanyId(), $connection->getId(), $snapshot, $now));
        $this->em()->flush();

        try {
            (static::getContainer()->get(ManageMoySkladConnectionAction::class))(
                new ManageConnectionCommand($connection->getCompanyId(), 'actor', 'delete', $connection->getId(), version: $connection->getVersion()),
            );
            self::fail('Deletion must be rejected.');
        } catch (ConnectionOperationException $e) {
            self::assertSame('connection_in_use', $e->reason);
        }
    }

    public function testSameExternalIdCannotBeStoredTwiceForConnection(): void
    {
        $this->resetDb();
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $now = new \DateTimeImmutable('2026-09-20T09:00:00+00:00');
        $snapshot = new CounterpartySnapshot('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Тест', 'individual', null, null, null, null, null, null, false, $now);
        $this->em()->persist($connection);
        $this->em()->persist(new MoySkladCounterparty('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $connection->getCompanyId(), $connection->getId(), $snapshot, $now));
        $this->em()->flush();

        $this->em()->persist(new MoySkladCounterparty('cccccccc-cccc-7ccc-8ccc-cccccccccccc', $connection->getCompanyId(), $connection->getId(), $snapshot, $now));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->flush();
    }

    public function testUtcMillisecondsSurvivePersistence(): void
    {
        $this->resetDb();
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $now = new \DateTimeImmutable('2026-09-20T09:00:00.123456+00:00');
        $snapshot = new CounterpartySnapshot('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '0', 'legal', null, null, null, null, null, null, false, $now);
        $record = new MoySkladCounterparty('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $connection->getCompanyId(), $connection->getId(), $snapshot, $now);
        $this->em()->persist($connection);
        $this->em()->persist($record);
        $this->em()->flush();
        $this->em()->clear();

        $stored = $this->em()->getConnection()->fetchOne('SELECT source_updated_at FROM moysklad_counterparties WHERE id = ?', [$record->getId()]);
        self::assertSame('2026-09-20 09:00:00.123', $stored);
        $restored = static::getContainer()->get(MoySkladCounterpartyRepository::class)->findByIdAndCompanyId($record->getId(), $connection->getCompanyId());
        self::assertNotNull($restored);
        self::assertSame('2026-09-20 09:00:00.123000', $restored->getSourceUpdatedAt()->format('Y-m-d H:i:s.u'));
    }

    public function testUtcWholeSecondSurvivesPersistenceWithoutCurrentMicroseconds(): void
    {
        $this->resetDb();
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $now = new \DateTimeImmutable('2026-09-20T09:00:00.000000+00:00');
        $snapshot = new CounterpartySnapshot('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Тест', 'legal', null, null, null, null, null, null, false, $now);
        $record = new MoySkladCounterparty('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $connection->getCompanyId(), $connection->getId(), $snapshot, $now);
        $this->em()->persist($connection);
        $this->em()->persist($record);
        $this->em()->flush();
        $this->em()->clear();

        $stored = $this->em()->getConnection()->fetchOne('SELECT source_updated_at FROM moysklad_counterparties WHERE id = ?', [$record->getId()]);
        self::assertSame('2026-09-20 09:00:00', $stored);
        $restored = static::getContainer()->get(MoySkladCounterpartyRepository::class)->findByIdAndCompanyId($record->getId(), $connection->getCompanyId());
        self::assertNotNull($restored);
        self::assertSame('2026-09-20 09:00:00.000000', $restored->getSourceUpdatedAt()->format('Y-m-d H:i:s.u'));
        self::assertFalse($restored->applySnapshot($snapshot, $now));
    }

    public function testOnlyOneRunningRunPerConnectionAndType(): void
    {
        $this->resetDb();
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $now = new \DateTimeImmutable('2026-09-20T09:00:00+00:00');
        $this->em()->persist($connection);
        $this->em()->persist(new MoySkladSyncRun('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $connection->getCompanyId(), $connection->getId(), 'counterparty', $now));
        $this->em()->flush();
        $this->em()->persist(new MoySkladSyncRun('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $connection->getCompanyId(), $connection->getId(), 'counterparty', $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->flush();
    }

    public function testDatabaseRejectsCounterpartyWithForeignCompanyId(): void
    {
        $this->resetDb();
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $now = new \DateTimeImmutable('2026-09-20T09:00:00+00:00');
        $snapshot = new CounterpartySnapshot('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Тест', 'legal', null, null, null, null, null, null, false, $now);
        $this->em()->persist($connection);
        $this->em()->persist(new MoySkladCounterparty('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', '99999999-9999-4999-8999-999999999999', $connection->getId(), $snapshot, $now));
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->em()->flush();
    }
}
