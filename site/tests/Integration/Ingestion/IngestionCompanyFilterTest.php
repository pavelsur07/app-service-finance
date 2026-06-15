<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Entity\IngestionTenantProbe;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class IngestionCompanyFilterTest extends IntegrationTestCase
{
    public function testCompanyFilterLimitsIngestionTenantOwnedEntities(): void
    {
        $this->resetDb();

        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();

        $probeA = new IngestionTenantProbe($companyA);
        $probeB = new IngestionTenantProbe($companyB);

        $this->em->persist($probeA);
        $this->em->persist($probeB);
        $this->em->flush();
        $this->em->clear();

        $filters = $this->em->getFilters();
        $filters->enable('company')->setParameter('companyId', $companyA);

        self::assertSame([$probeA->getId()], $this->probeIds());

        $filters->getFilter('company')->setParameter('companyId', $companyB);

        self::assertSame([$probeB->getId()], $this->probeIds());

        $filters->disable('company');

        self::assertEqualsCanonicalizing([$probeA->getId(), $probeB->getId()], $this->probeIds());
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
