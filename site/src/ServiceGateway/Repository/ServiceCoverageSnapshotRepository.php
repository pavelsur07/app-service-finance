<?php

declare(strict_types=1);

namespace App\ServiceGateway\Repository;

use App\ServiceGateway\Entity\ServiceCoverageSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceCoverageSnapshot>
 */
final class ServiceCoverageSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceCoverageSnapshot::class);
    }

    /**
     * @companyScopeExempt сводка покрытия сервиса без кабинетов и компаний, видна только ROLE_ADMIN
     */
    public function findOneByService(string $service): ?ServiceCoverageSnapshot
    {
        return $this->findOneBy(['service' => $service]);
    }

    public function save(ServiceCoverageSnapshot $snapshot): void
    {
        $this->getEntityManager()->persist($snapshot);
    }
}
