<?php

declare(strict_types=1);

namespace App\ServiceGateway\Repository;

use App\ServiceGateway\Entity\ServiceCoverageRow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceCoverageRow>
 */
final class ServiceCoverageRowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceCoverageRow::class);
    }

    /**
     * Удаляет строки сервиса сразу (DQL), вызывать внутри транзакции Action.
     *
     * @companyScopeExempt сводка покрытия сервиса без кабинетов и компаний, видна только ROLE_ADMIN
     */
    public function deleteByService(string $service): int
    {
        return $this->createQueryBuilder('r')
            ->delete()
            ->where('r.service = :service')
            ->setParameter('service', $service)
            ->getQuery()
            ->execute();
    }

    public function add(ServiceCoverageRow $row): void
    {
        $this->getEntityManager()->persist($row);
    }
}
