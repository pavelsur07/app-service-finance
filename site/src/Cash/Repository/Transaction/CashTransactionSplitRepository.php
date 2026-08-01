<?php

declare(strict_types=1);

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashTransactionSplit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

/**
 * @extends ServiceEntityRepository<CashTransactionSplit>
 */
final class CashTransactionSplitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashTransactionSplit::class);
    }

    /**
     * @return list<CashTransactionSplit>
     */
    public function findByTransaction(string $transactionId, string $companyId): array
    {
        Assert::uuid($transactionId);
        Assert::uuid($companyId);

        return $this->createQueryBuilder('s')
            ->andWhere('IDENTITY(s.cashTransaction) = :transactionId')
            ->andWhere('s.companyId = :companyId')
            ->setParameter('transactionId', $transactionId)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getResult();
    }

    public function countByCompany(string $companyId): int
    {
        Assert::uuid($companyId);

        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
