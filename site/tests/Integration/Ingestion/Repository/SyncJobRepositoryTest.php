<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Repository;

use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Enum\SyncJobStatus;
use App\Ingestion\Repository\SyncJobRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class SyncJobRepositoryTest extends IntegrationTestCase
{
    public function testFindByIdAndCompanyPreventsTenantLeak(): void
    {
        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();
        $jobB = $this->newJob($companyB);

        $this->em->persist($this->newJob($companyA));
        $this->em->persist($jobB);
        $this->em->flush();
        $this->em->clear();

        /** @var SyncJobRepository $repository */
        $repository = self::getContainer()->get(SyncJobRepository::class);

        self::assertNull($repository->findByIdAndCompany($jobB->getId(), $companyA));
        self::assertSame($jobB->getId(), $repository->findByIdAndCompany($jobB->getId(), $companyB)?->getId());
    }

    public function testFindLatestForResourceReturnsOnlyNonTerminalJob(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $completed = $this->newJob($companyId);
        $completed->markRunning();
        $completed->markCompleted();
        $active = $this->newJob($companyId);

        $this->em->persist($completed);
        $this->em->persist($active);
        $this->em->flush();
        $this->em->clear();

        /** @var SyncJobRepository $repository */
        $repository = self::getContainer()->get(SyncJobRepository::class);

        self::assertSame(
            $active->getId(),
            $repository->findLatestForResource($companyId, 'connection-1', 'resource-1', 'shop-1')?->getId(),
        );
    }

    public function testChildLookupsAndCountersUseCompanyId(): void
    {
        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();
        $parentA = $this->newJob($companyA);
        $parentB = $this->newJob($companyB);
        $childA = $this->newJob($companyA, $parentA->getId());
        $childB = $this->newJob($companyB, $parentB->getId());
        $childB->markRunning();
        $childB->markCompleted();

        foreach ([$parentA, $parentB, $childA, $childB] as $job) {
            $this->em->persist($job);
        }
        $this->em->flush();
        $this->em->clear();

        /** @var SyncJobRepository $repository */
        $repository = self::getContainer()->get(SyncJobRepository::class);

        self::assertSame([$childA->getId()], array_map(
            static fn (SyncJob $job): string => $job->getId(),
            $repository->findOpenChildrenOf($parentA->getId(), $companyA),
        ));
        self::assertSame(0, $repository->countChildrenByStatus($parentA->getId(), $companyA, SyncJobStatus::COMPLETED));
        self::assertSame(1, $repository->countChildrenByStatus($parentB->getId(), $companyB, SyncJobStatus::COMPLETED));
    }

    private function newJob(string $companyId, ?string $parentJobId = null): SyncJob
    {
        return new SyncJob(
            companyId: $companyId,
            connectionRef: 'connection-1',
            source: IngestSource::OZON,
            resourceType: 'resource-1',
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-30'),
            shopRef: 'shop-1',
            parentJobId: $parentJobId,
        );
    }
}
