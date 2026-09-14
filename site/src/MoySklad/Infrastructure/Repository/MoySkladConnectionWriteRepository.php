<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Repository;

use App\MoySklad\Entity\MoySkladConnection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MoySkladConnectionWriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoySkladConnection::class);
    }

    public function findByIdAndCompanyId(string $id, string $companyId): ?MoySkladConnection
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('c.companyId = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsByName(string $companyId, string $name, ?string $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.companyId = :companyId')
            ->andWhere('LOWER(c.name) = LOWER(:name)')
            ->setParameter('companyId', $companyId)
            ->setParameter('name', trim($name));

        if (null !== $excludeId) {
            $qb->andWhere('c.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** @return list<MoySkladConnection> */
    public function findBatchForCompany(string $companyId, ?string $afterId, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(max(1, min(100, $limit)));
        if (null !== $afterId) {
            $qb->andWhere('c.id > :afterId')->setParameter('afterId', $afterId);
        }

        return $qb->getQuery()->getResult();
    }
}
