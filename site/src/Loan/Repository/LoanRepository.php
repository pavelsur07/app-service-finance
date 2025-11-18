<?php

declare(strict_types=1);

namespace App\Loan\Repository;

use App\Entity\Company;
use App\Loan\Entity\Loan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Loan>
 */
class LoanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Loan::class);
    }

    /**
     * @return list<Loan>
     */
    public function findActiveByCompany(Company $company): array
    {
        /** @var list<Loan> $result */
        $result = $this->createQueryBuilder('loan')
            ->where('loan.company = :company')
            ->andWhere('loan.status = :status')
            ->orderBy('loan.startDate', 'ASC')
            ->addOrderBy('loan.createdAt', 'ASC')
            ->setParameter('company', $company)
            ->setParameter('status', 'active')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
