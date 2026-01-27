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
        $result = $this->createQueryBuilder('member')
            ->andWhere('member.company = :company')
            ->andWhere('member.status = :status')
            ->setParameter('company', $company)
            ->setParameter('status', CompanyMember::STATUS_ACTIVE)
            ->orderBy('member.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findOneByCompanyAndUser(Company $company, User $user): ?CompanyMember
    {
        return $this->createQueryBuilder('member')
            ->andWhere('member.company = :company')
            ->andWhere('member.user = :user')
            ->setParameter('company', $company)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
