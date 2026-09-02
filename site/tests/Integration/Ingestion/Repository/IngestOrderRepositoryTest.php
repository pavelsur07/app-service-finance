<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Repository;

use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\IngestOrderRepository;
use App\Tests\Builders\Ingestion\IngestOrderBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class IngestOrderRepositoryTest extends IntegrationTestCase
{
    private IngestOrderRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IngestOrderRepository::class);
    }

    public function testLookupsAreScopedToCompany(): void
    {
        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();

        $this->persist(IngestOrderBuilder::anOrder()->forCompany($companyA)->withExternalId('shared-1')->build());
        $this->persist(IngestOrderBuilder::anOrder()->forCompany($companyB)->withExternalId('shared-1')->build());
        $this->em->flush();

        $found = $this->repository->findByExternalId($companyA, IngestSource::OZON, 'connection-1', 'shared-1');

        self::assertNotNull($found);
        self::assertSame($companyA, $found->getCompanyId());
    }

    /**
     * Регрессия: posting_number уникален в пределах кабинета продавца, а не
     * глобально. Без connectionRef в ключе два кабинета одной компании
     * слились бы в одну запись, и статусы с позициями одного подключения
     * затирали бы другое.
     */
    public function testSamePostingNumberInTwoConnectionsStaysSeparate(): void
    {
        $companyId = Uuid::uuid7()->toString();

        $this->persist(IngestOrderBuilder::anOrder()->forCompany($companyId)->withConnectionRef('cabinet-a')->withExternalId('111-2222-3')->build());
        $this->persist(IngestOrderBuilder::anOrder()->forCompany($companyId)->withConnectionRef('cabinet-b')->withExternalId('111-2222-3')->build());
        $this->em->flush();

        $a = $this->repository->findByExternalId($companyId, IngestSource::OZON, 'cabinet-a', '111-2222-3');
        $b = $this->repository->findByExternalId($companyId, IngestSource::OZON, 'cabinet-b', '111-2222-3');

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotSame($a->getId(), $b->getId());
    }

    /**
     * Терминальные заказы перепрашивать незачем — именно это ограничивает
     * объём часового цикла.
     */
    public function testRefreshSelectionSkipsTerminalAndStoppedOrders(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $orderedAt = new \DateTimeImmutable('-3 days');

        $this->persist(IngestOrderBuilder::anOrder()->forCompany($companyId)->withExternalId('live-1')
            ->withStatus(IngestOrderStatus::SHIPPED, 'delivering')->orderedAt($orderedAt)->build());
        $this->persist(IngestOrderBuilder::anOrder()->forCompany($companyId)->withExternalId('done-1')
            ->withStatus(IngestOrderStatus::DELIVERED, 'delivered')->orderedAt($orderedAt)->build());
        $this->persist(IngestOrderBuilder::anOrder()->forCompany($companyId)->withExternalId('unknown-1')
            ->withStatus(IngestOrderStatus::UNKNOWN, 'что-то новое')->orderedAt($orderedAt)->build());

        $stopped = IngestOrderBuilder::anOrder()->forCompany($companyId)->withExternalId('stuck-1')
            ->withStatus(IngestOrderStatus::SHIPPED, 'delivering')->orderedAt($orderedAt)->build();
        $stopped->stopRefreshing(new \DateTimeImmutable());
        $this->persist($stopped);
        $this->em->flush();

        $candidates = $this->repository->findNonTerminalForRefresh(
            $companyId,
            IngestSource::OZON,
            'connection-1',
            new \DateTimeImmutable('-30 days'),
            100,
        );

        $ids = array_map(static fn ($o): string => $o->getExternalId(), $candidates);
        sort($ids);

        // UNKNOWN нетерминален намеренно: незнакомый статус надо дожать, а не
        // считать заказ закрытым.
        self::assertSame(['live-1', 'unknown-1'], $ids);
    }

    public function testRefreshSelectionOrdersByStalestObservationFirst(): void
    {
        $companyId = Uuid::uuid7()->toString();

        $this->persist(IngestOrderBuilder::anOrder()->forCompany($companyId)->withExternalId('fresh')
            ->statusObservedAt(new \DateTimeImmutable('-1 hour'))->build());
        $this->persist(IngestOrderBuilder::anOrder()->forCompany($companyId)->withExternalId('stale')
            ->statusObservedAt(new \DateTimeImmutable('-10 hours'))->build());
        $this->em->flush();

        $candidates = $this->repository->findNonTerminalForRefresh(
            $companyId,
            IngestSource::OZON,
            'connection-1',
            new \DateTimeImmutable('-30 days'),
            100,
        );

        self::assertSame('stale', $candidates[0]->getExternalId());
    }

    public function testStuckOrdersAreDiscoverable(): void
    {
        $companyId = Uuid::uuid7()->toString();

        $this->persist(IngestOrderBuilder::anOrder()->forCompany($companyId)->withExternalId('ancient')
            ->orderedAt(new \DateTimeImmutable('-90 days'))->build());
        $this->persist(IngestOrderBuilder::anOrder()->forCompany($companyId)->withExternalId('recent')
            ->orderedAt(new \DateTimeImmutable('-2 days'))->build());
        $this->em->flush();

        $stuck = $this->repository->findStuck($companyId, new \DateTimeImmutable('-30 days'), 100);

        self::assertCount(1, $stuck);
        self::assertSame('ancient', $stuck[0]->getExternalId());
    }

    private function persist(object $entity): void
    {
        $this->em->persist($entity);
    }
}
