<?php

namespace App\Cash\Repository\Import;

use App\Cash\Entity\Import\CashFileImportProfile;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CashFileImportProfile>
 */
class CashFileImportProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashFileImportProfile::class);
    }

    /**
     * @return CashFileImportProfile[]
     */
    public function findByCompanyAndType(Company $company, string $type): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.company = :company')
            ->andWhere('p.type = :type')
            ->setParameter('company', $company)
            ->setParameter('type', $type)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
