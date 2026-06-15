<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Entity\IngestionTenantProbe;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\EntityRepository;
use Ramsey\Uuid\Uuid;

final class IngestionCompanyFilterTest extends IntegrationTestCase
{
    public function testCompanyFilterPreventsTenantLeakAcrossOrmReadPaths(): void
    {
        $this->resetDb();

        [$companyA, $companyB, $probeA, $probeB] = $this->createTwoCompanyProbes();

        $filters = $this->em->getFilters();
        $filters->enable('company')->setParameter('companyId', $companyA);

        /** @var EntityRepository<IngestionTenantProbe> $repository */
        $repository = $this->em->getRepository(IngestionTenantProbe::class);

        $findAllIds = array_map(
            static fn (IngestionTenantProbe $probe): string => $probe->getId(),
            $repository->findAll(),
        );
        self::assertSame([$probeA->getId()], $findAllIds);

        self::assertNull($repository->findOneBy(['id' => $probeB->getId()]));
        self::assertSame($probeA->getId(), $repository->findOneBy(['id' => $probeA->getId()])?->getId());

        self::assertSame([$probeA->getId()], $this->probeIds());

        $filters->getFilter('company')->setParameter('companyId', $companyB);
        self::assertSame([$probeB->getId()], $this->probeIds());
        $filters->disable('company');
    }

    public function testSystemQueryWithDisabledCompanyFilterSeesAllCompanies(): void
    {
        $this->resetDb();

        [, , $probeA, $probeB] = $this->createTwoCompanyProbes();

        $filters = $this->em->getFilters();
        $filters->enable('company')->setParameter('companyId', $probeA->getCompanyId());
        $filters->disable('company');

        self::assertEqualsCanonicalizing([$probeA->getId(), $probeB->getId()], $this->probeIds());
    }

    /**
     * @return array{0: string, 1: string, 2: IngestionTenantProbe, 3: IngestionTenantProbe}
     */
    private function createTwoCompanyProbes(): array
    {
        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();

        $probeA = new IngestionTenantProbe($companyA);
        $probeB = new IngestionTenantProbe($companyB);

        $this->em->persist($probeA);
        $this->em->persist($probeB);
        $this->em->flush();
        $this->em->clear();

        return [$companyA, $companyB, $probeA, $probeB];
    }

    /**
     * @return list<string>
     */
    private function probeIds(): array
    {
        return $this->em->createQueryBuilder()
            ->select('probe.id')
            ->from(IngestionTenantProbe::class, 'probe')
            ->orderBy('probe.createdAt', 'ASC')
            ->addOrderBy('probe.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }
}
