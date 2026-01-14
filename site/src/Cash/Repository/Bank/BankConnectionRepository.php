<?php

namespace App\Cash\Repository\Bank;

use App\Cash\Entity\Bank\BankConnection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BankConnection>
 */
class BankConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankConnection::class);
    }

    /**
     * @return list<BankConnection>
     */
    public function findActiveByBankCode(string $bankCode): array
    {
        return $this->createQueryBuilder('connection')
            ->andWhere('connection.bankCode = :bankCode')
            ->andWhere('connection.isActive = :active')
            ->setParameter('bankCode', $bankCode)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }
}
