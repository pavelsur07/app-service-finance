<?php

namespace App\Cash\Repository\Bank;

use App\Cash\Entity\Bank\BankConnection;
use App\Entity\Company;
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
     * @return BankConnection[]
     */
    public function findActiveByBankCode(string $bankCode): array
    {
        return $this->createQueryBuilder('bc')
            ->andWhere('bc.bankCode = :bankCode')
            ->andWhere('bc.isActive = :isActive')
            ->setParameter('bankCode', $bankCode)
            ->setParameter('isActive', true)
            ->getQuery()
            ->getResult();
    }

    public function findActiveByCompanyAndBankCode(Company $company, string $bankCode): ?BankConnection
    {
        return $this->createQueryBuilder('bc')
            ->andWhere('bc.company = :company')
            ->andWhere('bc.bankCode = :bankCode')
            ->andWhere('bc.isActive = :isActive')
            ->setParameter('company', $company)
            ->setParameter('bankCode', $bankCode)
            ->setParameter('isActive', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
