<?php

namespace App\Company\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompanyMember>
 */
class CompanyMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanyMember::class);
    }

    /**
     * @return list<CompanyMember>
     */
    public function findActiveByCompany(Company $company): array
    {
        /** @var list<CompanyMember> $result */
        $result = $this->createQueryBuilder('companyMember')
            ->andWhere('companyMember.company = :company')
            ->andWhere('companyMember.status = :status')
            ->setParameter('company', $company)
            ->setParameter('status', CompanyMember::STATUS_ACTIVE)
            ->orderBy('companyMember.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<CompanyMember>
     */
    public function findByCompany(Company $company): array
    {
        /** @var list<CompanyMember> $result */
        $result = $this->createQueryBuilder('companyMember')
            ->andWhere('companyMember.company = :company')
            ->setParameter('company', $company)
            ->orderBy('companyMember.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findOneByCompanyAndUser(Company $company, User $user): ?CompanyMember
    {
        return $this->createQueryBuilder('companyMember')
            ->andWhere('companyMember.company = :company')
            ->andWhere('companyMember.user = :user')
            ->setParameter('company', $company)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
