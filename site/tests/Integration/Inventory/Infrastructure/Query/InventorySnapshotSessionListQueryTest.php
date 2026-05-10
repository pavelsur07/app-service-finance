<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Infrastructure\Query;

use App\Inventory\Enum\SnapshotTriggerType;
use App\Inventory\Infrastructure\Query\InventorySnapshotSessionListQuery;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Inventory\InventorySnapshotSessionBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;

final class InventorySnapshotSessionListQueryTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000601';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000602';

    private InventorySnapshotSessionListQuery $query;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = self::getContainer()->get(InventorySnapshotSessionListQuery::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testReturnsOnlyRecordsForRequestedCompany(): void
    {
        $own = InventorySnapshotSessionBuilder::aSession()->withCompanyId(self::COMPANY_ID)->build();
        $foreign = InventorySnapshotSessionBuilder::aSession()->withCompanyId(self::OTHER_COMPANY_ID)->build();

        $this->em->persist($own);
        $this->em->persist($foreign);
        $this->em->flush();

        $rows = $this->query->buildQueryBuilder(self::COMPANY_ID)->executeQuery()->fetchAllAssociative();

        self::assertCount(1, $rows);
        self::assertSame(self::COMPANY_ID, $rows[0]['company_id']);
        self::assertSame($own->getId(), $rows[0]['id']);
    }

    public function testSortsNewestFirst(): void
    {
        $old = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId(self::COMPANY_ID)
            ->withSource(MarketplaceType::WILDBERRIES)
            ->withTriggerType(SnapshotTriggerType::Manual)
            ->build();

        $new = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId(self::COMPANY_ID)
            ->withSource(MarketplaceType::OZON)
            ->withTriggerType(SnapshotTriggerType::Retry)
            ->build();

        $this->em->persist($old);
        $this->em->persist($new);
        $this->em->flush();

        $this->connection->update('inventory_snapshot_sessions', ['created_at' => '2026-01-01 10:00:00'], ['id' => $old->getId()]);
        $this->connection->update('inventory_snapshot_sessions', ['created_at' => '2026-01-02 10:00:00'], ['id' => $new->getId()]);

        $rows = $this->query->buildQueryBuilder(self::COMPANY_ID)->executeQuery()->fetchAllAssociative();

        self::assertCount(2, $rows);
        self::assertSame($new->getId(), $rows[0]['id']);
        self::assertSame($old->getId(), $rows[1]['id']);
    }

    public function testProvidesProductionPaginationWithDefaults(): void
    {
        for ($i = 1; $i <= 35; ++$i) {
            $session = InventorySnapshotSessionBuilder::aSession()
                ->withCompanyId(self::COMPANY_ID)
                ->withCorrelationId(sprintf('33333333-3333-7333-8333-%012d', $i))
                ->build();
            $this->em->persist($session);
        }
        $this->em->flush();

        $pager = $this->query->getPage(self::COMPANY_ID, 1);

        self::assertSame(35, $pager->getNbResults());
        self::assertSame(InventorySnapshotSessionListQuery::PER_PAGE, $pager->getMaxPerPage());
        self::assertSame(1, $pager->getCurrentPage());
        self::assertSame(2, $pager->getNbPages());
        self::assertCount(30, iterator_to_array($pager->getCurrentPageResults()));
    }

    public function testDoesNotSelectRawPayloadColumns(): void
    {
        $sql = $this->query->buildQueryBuilder(self::COMPANY_ID)->getSQL();

        self::assertStringNotContainsString('*', $sql);
        self::assertStringNotContainsString('raw_response', $sql);
        self::assertStringNotContainsString('payload', $sql);
    }
}
