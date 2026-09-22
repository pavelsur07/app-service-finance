<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Domain\ProductSnapshot;
use App\MoySklad\Domain\StoreSnapshot;
use App\MoySklad\Domain\VariantSnapshot;
use App\MoySklad\Entity\MoySkladProduct;
use App\MoySklad\Entity\MoySkladStockSnapshot;
use App\MoySklad\Entity\MoySkladStockSnapshotLine;
use App\MoySklad\Entity\MoySkladStore;
use App\MoySklad\Entity\MoySkladVariant;
use App\MoySklad\Infrastructure\Repository\MoySkladStockSnapshotLineRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladStockSnapshotRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladStoreRepository;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Builders\MoySklad\MoySkladStoreBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class StockStorageTest extends WebTestCaseBase
{
    public function testPersistsCompletedSnapshotWithProductAndVariantLines(): void
    {
        $this->resetDb();
        [$connection, $product, $variant, $store] = $this->catalog();
        $startedAt = new \DateTimeImmutable('2026-09-21T08:00:00.123+00:00');
        $snapshot = new MoySkladStockSnapshot('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $connection->getCompanyId(), $connection->getId(), $startedAt);
        $productLine = new MoySkladStockSnapshotLine('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $connection->getCompanyId(), $connection->getId(), $snapshot->getId(), $store->getExternalId(), 'product', $product->getExternalId(), '-30', '1.25', '3');
        $variantLine = new MoySkladStockSnapshotLine('cccccccc-cccc-7ccc-8ccc-cccccccccccc', $connection->getCompanyId(), $connection->getId(), $snapshot->getId(), $store->getExternalId(), 'variant', $variant->getExternalId(), '4.125', '0.125', '0');
        foreach ([$connection, $product, $variant, $store, $snapshot, $productLine, $variantLine] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $snapshot->complete(new \DateTimeImmutable('2026-09-21T08:01:00.456+00:00'));
        $this->em()->flush();
        $this->em()->clear();

        $stores = static::getContainer()->get(MoySkladStoreRepository::class);
        $snapshots = static::getContainer()->get(MoySkladStockSnapshotRepository::class);
        $lines = static::getContainer()->get(MoySkladStockSnapshotLineRepository::class);
        self::assertNotNull($stores->findByExternalId($connection->getCompanyId(), $connection->getId(), $store->getExternalId()));
        self::assertNull($stores->findByExternalId('99999999-9999-4999-8999-999999999999', $connection->getId(), $store->getExternalId()));
        self::assertSame($snapshot->getId(), $snapshots->latestCompleted($connection->getCompanyId(), $connection->getId())?->getId());
        self::assertNull($snapshots->latestCompleted('99999999-9999-4999-8999-999999999999', $connection->getId()));
        self::assertSame(2, $lines->countForSnapshot($connection->getCompanyId(), $snapshot->getId()));
        self::assertSame(0, $lines->countForSnapshot('99999999-9999-4999-8999-999999999999', $snapshot->getId()));
        $storedMetrics = $this->em()->getConnection()->fetchAssociative(
            'SELECT stock, reserve, in_transit FROM moysklad_stock_snapshot_lines WHERE id = ?',
            [$productLine->getId()],
        );
        self::assertSame(
            ['stock' => '-30.0000000000', 'reserve' => '1.2500000000', 'in_transit' => '3.0000000000'],
            $storedMetrics,
        );
    }

    public function testRejectsDuplicateAssortmentStoreLine(): void
    {
        $this->resetDb();
        [$connection, $product, , $store] = $this->catalog();
        $snapshot = new MoySkladStockSnapshot('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $connection->getCompanyId(), $connection->getId(), new \DateTimeImmutable());
        foreach ([$connection, $product, $store, $snapshot] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->persist(new MoySkladStockSnapshotLine('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $connection->getCompanyId(), $connection->getId(), $snapshot->getId(), $store->getExternalId(), 'product', $product->getExternalId(), '1', '0', '0'));
        $this->em()->flush();
        $this->em()->persist(new MoySkladStockSnapshotLine('cccccccc-cccc-7ccc-8ccc-cccccccccccc', $connection->getCompanyId(), $connection->getId(), $snapshot->getId(), $store->getExternalId(), 'product', $product->getExternalId(), '2', '0', '0'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->flush();
    }

    public function testRejectsLineWithUnknownStore(): void
    {
        $this->resetDb();
        [$connection, $product] = $this->catalog();
        $snapshot = new MoySkladStockSnapshot('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $connection->getCompanyId(), $connection->getId(), new \DateTimeImmutable());
        foreach ([$connection, $product, $snapshot] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->persist(new MoySkladStockSnapshotLine('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $connection->getCompanyId(), $connection->getId(), $snapshot->getId(), 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'product', $product->getExternalId(), '1', '0', '0'));

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->em()->flush();
    }

    public function testRejectsLineWhoseStoreBelongsToAnotherTenant(): void
    {
        $this->resetDb();
        [$connection, $product] = $this->catalog();
        $foreignCompanyId = '99999999-9999-4999-8999-999999999999';
        $foreignConnection = MoySkladConnectionBuilder::aConnection()->withIndex(2)->withCompanyId($foreignCompanyId)->build();
        $now = new \DateTimeImmutable('2026-09-21T08:00:00+00:00');
        $foreignStore = new MoySkladStore('eeeeeeee-eeee-7eee-8eee-eeeeeeeeeeee', $foreignCompanyId, $foreignConnection->getId(), new StoreSnapshot('00000000-0000-4000-8000-000000000401', 'Foreign store', 'foreign-store', null, '', false, $now), $now);
        $snapshot = new MoySkladStockSnapshot('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $connection->getCompanyId(), $connection->getId(), $now);
        foreach ([$connection, $product, $foreignConnection, $foreignStore, $snapshot] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->persist(new MoySkladStockSnapshotLine('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $connection->getCompanyId(), $connection->getId(), $snapshot->getId(), $foreignStore->getExternalId(), 'product', $product->getExternalId(), '1', '0', '0'));

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->em()->flush();
    }

    public function testOnlyOneBuildingSnapshotPerConnection(): void
    {
        $this->resetDb();
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $now = new \DateTimeImmutable('2026-09-21T08:00:00+00:00');
        $this->em()->persist($connection);
        $this->em()->persist(new MoySkladStockSnapshot('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $connection->getCompanyId(), $connection->getId(), $now));
        $this->em()->persist(new MoySkladStockSnapshot('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $connection->getCompanyId(), $connection->getId(), $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->flush();
    }

    public function testDatabaseRejectsInconsistentSnapshotStatus(): void
    {
        $this->resetDb();
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $this->em()->persist($connection);
        $this->em()->flush();

        $this->expectException(DriverException::class);
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO moysklad_stock_snapshots (id, company_id, connection_id, status, started_at, completed_at) VALUES (?, ?, ?, 'completed', NOW(), NULL)",
            ['aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $connection->getCompanyId(), $connection->getId()],
        );
    }

    public function testDatabaseRejectsLineWithoutAssortment(): void
    {
        $this->resetDb();
        [$connection, , , $store] = $this->catalog();
        $snapshot = new MoySkladStockSnapshot('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $connection->getCompanyId(), $connection->getId(), new \DateTimeImmutable());
        foreach ([$connection, $store, $snapshot] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $this->expectException(DriverException::class);
        $this->em()->getConnection()->executeStatement(
            'INSERT INTO moysklad_stock_snapshot_lines (id, company_id, connection_id, snapshot_id, store_external_id, product_external_id, variant_external_id, stock, reserve, in_transit) VALUES (?, ?, ?, ?, ?, NULL, NULL, 0, 0, 0)',
            ['bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $connection->getCompanyId(), $connection->getId(), $snapshot->getId(), $store->getExternalId()],
        );
    }

    public function testCompletedSnapshotLinesCannotBeChanged(): void
    {
        $this->resetDb();
        [$connection, $product, , $store] = $this->catalog();
        $snapshot = new MoySkladStockSnapshot('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $connection->getCompanyId(), $connection->getId(), new \DateTimeImmutable());
        $line = new MoySkladStockSnapshotLine('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $connection->getCompanyId(), $connection->getId(), $snapshot->getId(), $store->getExternalId(), 'product', $product->getExternalId(), '1', '0', '0');
        foreach ([$connection, $product, $store, $snapshot, $line] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $snapshot->complete(new \DateTimeImmutable());
        $this->em()->flush();

        $this->expectException(DriverException::class);
        $this->em()->getConnection()->executeStatement('UPDATE moysklad_stock_snapshot_lines SET stock = 2 WHERE id = ?', [$line->getId()]);
    }

    public function testCompletedSnapshotCannotBeReopened(): void
    {
        $this->resetDb();
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $snapshot = new MoySkladStockSnapshot('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $connection->getCompanyId(), $connection->getId(), new \DateTimeImmutable());
        $this->em()->persist($connection);
        $this->em()->persist($snapshot);
        $this->em()->flush();
        $snapshot->complete(new \DateTimeImmutable());
        $this->em()->flush();

        $this->expectException(DriverException::class);
        $this->em()->getConnection()->executeStatement(
            "UPDATE moysklad_stock_snapshots SET status = 'building', completed_at = NULL WHERE id = ?",
            [$snapshot->getId()],
        );
    }

    /** @return array{\App\MoySklad\Entity\MoySkladConnection, MoySkladProduct, MoySkladVariant, MoySkladStore} */
    private function catalog(): array
    {
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $now = new \DateTimeImmutable('2026-09-21T08:00:00+00:00');
        $product = new MoySkladProduct('11111111-1111-7111-8111-111111111112', $connection->getCompanyId(), $connection->getId(), new ProductSnapshot('00000000-0000-4000-8000-000000000001', 'Product', 'product-external', null, null, 1, false, $now), $now);
        $variant = new MoySkladVariant('11111111-1111-7111-8111-111111111113', $connection->getCompanyId(), $connection->getId(), new VariantSnapshot('00000000-0000-4000-8000-000000000101', $product->getExternalId(), 'Variant', 'variant-external', null, null, [], false, $now), $now);
        $store = MoySkladStoreBuilder::aStore()->withTenant($connection->getCompanyId(), $connection->getId())->build();

        return [$connection, $product, $variant, $store];
    }
}
