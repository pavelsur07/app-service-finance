<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\ReconciliationSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ReconciliationSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReconciliationSession::class);
    }

    public function findByIdAndCompany(string $id, string $companyId): ?ReconciliationSession
    {
        return $this->findOneBy(['id' => $id, 'companyId' => $companyId]);
    }

    /**
     * @return ReconciliationSession[]
     */
    public function findByCompanyOrderedByDate(string $companyId, int $limit = 20, int $offset = 0): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function save(ReconciliationSession $session): void
    {
        $this->getEntityManager()->persist($session);
    }

    public function countByCompany(string $companyId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
