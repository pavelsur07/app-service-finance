<?php

namespace App\Shared\Repository;

use App\Shared\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;

class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @return Pagerfanta<AuditLog>
     */
    public function paginateForEntityClass(string $companyId, string $entityClass, int $page, int $perPage): Pagerfanta
    {
        $qb = $this->createQueryBuilder('al')
            ->andWhere('al.companyId = :companyId')
            ->andWhere('al.entityClass = :entityClass')
            ->setParameter('companyId', $companyId)
            ->setParameter('entityClass', $entityClass)
            ->orderBy('al.createdAt', 'DESC');

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($perPage);
        $pager->setAllowOutOfRangePages(true);
        $pager->setCurrentPage(max(1, $page));

        return $pager;
    }
}
