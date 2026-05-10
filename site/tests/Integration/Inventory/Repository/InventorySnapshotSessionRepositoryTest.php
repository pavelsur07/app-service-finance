<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Repository;

use App\Inventory\Enum\SnapshotSessionStatus;
use App\Inventory\Repository\InventorySnapshotSessionRepository;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Inventory\InventorySnapshotSessionBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class InventorySnapshotSessionRepositoryTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000701';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000702';

    private InventorySnapshotSessionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(InventorySnapshotSessionRepository::class);
    }

    public function testFindLatestActiveByCompanyAndSourceReturnsOwnCompanySession(): void
    {
        $pending = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId(self::COMPANY_ID)
            ->withSource(MarketplaceType::OZON)
            ->withCorrelationId('33333333-3333-7333-8333-000000000701')
            ->build();

        $inProgress = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId(self::COMPANY_ID)
            ->withSource(MarketplaceType::OZON)
            ->withCorrelationId('33333333-3333-7333-8333-000000000702')
            ->build();
        $inProgress->markInProgress();

        $this->em->persist($pending);
        $this->em->persist($inProgress);
        $this->em->flush();

        $this->em->getConnection()->update('inventory_snapshot_sessions', ['started_at' => '2026-04-01 10:00:00'], ['id' => $pending->getId()]);
        $this->em->getConnection()->update('inventory_snapshot_sessions', ['started_at' => '2026-04-02 10:00:00'], ['id' => $inProgress->getId()]);

        $this->em->clear();

        $found = $this->repository->findLatestActiveByCompanyAndSource(self::COMPANY_ID, MarketplaceType::OZON);

        self::assertNotNull($found);
        self::assertSame($inProgress->getId(), $found->getId());
        self::assertSame(SnapshotSessionStatus::InProgress, $found->getStatus());
    }

    public function testTerminalStatusIsNotActive(): void
    {
        $completed = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId(self::COMPANY_ID)
            ->withSource(MarketplaceType::OZON)
            ->withCorrelationId('33333333-3333-7333-8333-000000000703')
            ->build();
        $completed->markCompleted();

        $this->em->persist($completed);
        $this->em->flush();
        $this->em->clear();

        $found = $this->repository->findLatestActiveByCompanyAndSource(self::COMPANY_ID, MarketplaceType::OZON);

        self::assertNull($found);
    }

    public function testForeignCompanySessionIsNotVisible(): void
    {
        $foreign = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId(self::OTHER_COMPANY_ID)
            ->withSource(MarketplaceType::OZON)
            ->withCorrelationId('33333333-3333-7333-8333-000000000704')
            ->build();

        $this->em->persist($foreign);
        $this->em->flush();
        $this->em->clear();

        $found = $this->repository->findLatestActiveByCompanyAndSource(self::COMPANY_ID, MarketplaceType::OZON);

        self::assertNull($found);
    }

    public function testOtherSourceDoesNotBlockRequestedSource(): void
    {
        $otherSource = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId(self::COMPANY_ID)
            ->withSource(MarketplaceType::WILDBERRIES)
            ->withCorrelationId('33333333-3333-7333-8333-000000000705')
            ->build();
        $otherSource->markInProgress();

        $this->em->persist($otherSource);
        $this->em->flush();
        $this->em->clear();

        $found = $this->repository->findLatestActiveByCompanyAndSource(self::COMPANY_ID, MarketplaceType::OZON);

        self::assertNull($found);
    }

    public function testRepositoryDoesNotFlushOnPersistWithoutManualFlush(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId(self::COMPANY_ID)
            ->withSource(MarketplaceType::OZON)
            ->withCorrelationId('33333333-3333-7333-8333-000000000706')
            ->build();

        $this->em->persist($session);

        $count = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_snapshot_sessions');
        self::assertSame(0, $count);
    }

    public function testFindByIdAndCompanyReturnsSessionForMatchingPair(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId(self::COMPANY_ID)
            ->withSource(MarketplaceType::OZON)
            ->withCorrelationId('33333333-3333-7333-8333-000000000707')
            ->build();

        $this->em->persist($session);
        $this->em->flush();
        $this->em->clear();

        $found = $this->repository->findByIdAndCompany($session->getId(), self::COMPANY_ID);

        self::assertNotNull($found);
        self::assertSame($session->getId(), $found->getId());
    }

    public function testFindByIdAndCompanyReturnsNullForForeignCompany(): void
    {
        $session = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId(self::OTHER_COMPANY_ID)
            ->withSource(MarketplaceType::OZON)
            ->withCorrelationId('33333333-3333-7333-8333-000000000708')
            ->build();

        $this->em->persist($session);
        $this->em->flush();
        $this->em->clear();

        $found = $this->repository->findByIdAndCompany($session->getId(), self::COMPANY_ID);

        self::assertNull($found);
    }

    public function testFindByIdAndCompanyThrowsForInvalidId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->findByIdAndCompany('not-a-uuid', self::COMPANY_ID);
    }

    public function testFindByIdAndCompanyThrowsForInvalidCompanyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->findByIdAndCompany('33333333-3333-7333-8333-000000000709', 'not-a-uuid');
    }

    public function testFindLatestActiveByCompanyAndSourceThrowsForInvalidCompanyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->findLatestActiveByCompanyAndSource('not-a-uuid', MarketplaceType::OZON);
    }
}
