<?php

namespace App\Company\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyRole;
use App\Company\Security\SystemCompanyRoles;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompanyRole>
 */
class CompanyRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanyRole::class);
    }

    public function createAssignableForCompanyQueryBuilder(Company $company): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.company IS NULL OR r.company = :company')
            ->andWhere('r.id != :ownerId')
            ->setParameter('company', $company)
            ->setParameter('ownerId', SystemCompanyRoles::OWNER_ID)
            ->orderBy('r.name', 'ASC');
    }

    /**
     * @return list<CompanyRole>
     */
    public function findAssignableForCompany(Company $company): array
    {
        return $this->createAssignableForCompanyQueryBuilder($company)
            ->getQuery()
            ->getResult();
    }

    public function save(CompanyRole $role): void
    {
        $this->getEntityManager()->persist($role);
        $this->getEntityManager()->flush();
    }

    public function remove(CompanyRole $role): void
    {
        $this->getEntityManager()->remove($role);
        $this->getEntityManager()->flush();
    }
}
