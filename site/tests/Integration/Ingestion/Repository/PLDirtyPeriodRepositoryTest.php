<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Repository;

use App\Ingestion\Entity\PLDirtyPeriod;
use App\Ingestion\Enum\PLDirtyPeriodReason;
use App\Ingestion\Enum\PLDirtyPeriodStatus;
use App\Ingestion\Repository\PLDirtyPeriodRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class PLDirtyPeriodRepositoryTest extends IntegrationTestCase
{
    public function testFindOneUsesCompanyAndShopScope(): void
    {
        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();
        $periodB = new PLDirtyPeriod($companyB, 2026, 2, 'ozon:shop-1', PLDirtyPeriodReason::INGEST);

        $this->em->persist(new PLDirtyPeriod($companyA, 2026, 2, 'ozon:shop-1', PLDirtyPeriodReason::INGEST));
        $this->em->persist($periodB);
        $this->em->flush();
        $this->em->clear();

        /** @var PLDirtyPeriodRepository $repository */
        $repository = self::getContainer()->get(PLDirtyPeriodRepository::class);

        self::assertNull($repository->findOne($companyA, 2026, 2, 'missing-shop'));
        self::assertNull($repository->findOne($companyA, 2026, 2, ''));
        self::assertSame($periodB->getId(), $repository->findOne($companyB, 2026, 2, 'ozon:shop-1')?->getId());
    }

    public function testPendingLookupsAndCountersAreScoped(): void
    {
        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();

        $pendingA1 = new PLDirtyPeriod($companyA, 2026, 1, '', PLDirtyPeriodReason::INGEST);
        usleep(1000);
        $pendingA2 = new PLDirtyPeriod($companyA, 2026, 2, '', PLDirtyPeriodReason::MANUAL);
        $doneA = new PLDirtyPeriod($companyA, 2026, 3, '', PLDirtyPeriodReason::REMAP);
        $doneA->markRebuilding();
        $doneA->markDone();
        $pendingB = new PLDirtyPeriod($companyB, 2026, 1, '', PLDirtyPeriodReason::INGEST);

        foreach ([$pendingA1, $pendingA2, $doneA, $pendingB] as $period) {
            $this->em->persist($period);
        }
        $this->em->flush();
        $this->em->clear();

        /** @var PLDirtyPeriodRepository $repository */
        $repository = self::getContainer()->get(PLDirtyPeriodRepository::class);

        self::assertSame([$pendingA1->getId(), $pendingA2->getId()], array_map(
            static fn (PLDirtyPeriod $period): string => $period->getId(),
            $repository->findPendingForCompany($companyA),
        ));
        self::assertSame(2, $repository->countByStatus($companyA, PLDirtyPeriodStatus::PENDING));
        self::assertSame(1, $repository->countByStatus($companyA, PLDirtyPeriodStatus::DONE));
        self::assertCount(2, $repository->findPending(2));
    }
}
