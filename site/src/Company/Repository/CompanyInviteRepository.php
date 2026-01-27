<?php

namespace App\Company\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyInvite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompanyInvite>
 */
class CompanyInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanyInvite::class);
    }

    public function findPendingByCompanyAndEmail(Company $company, string $email, \DateTimeImmutable $now): ?CompanyInvite
    {
        return $this->createPendingInviteQueryBuilder($now)
            ->andWhere('invite.company = :company')
            ->andWhere('invite.email = :email')
            ->setParameter('company', $company)
            ->setParameter('email', mb_strtolower($email))
            ->orderBy('invite.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByTokenHash(string $tokenHash): ?CompanyInvite
    {
        return $this->createQueryBuilder('invite')
            ->andWhere('invite.tokenHash = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<CompanyInvite>
     */
    public function findPendingByCompany(Company $company, \DateTimeImmutable $now): array
    {
        return $this->createPendingInviteQueryBuilder($now)
            ->andWhere('invite.company = :company')
            ->setParameter('company', $company)
            ->orderBy('invite.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    private function createPendingInviteQueryBuilder(\DateTimeImmutable $now): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('invite')
            ->andWhere('invite.acceptedAt IS NULL')
            ->andWhere('invite.revokedAt IS NULL')
            ->andWhere('invite.expiresAt > :now')
            ->setParameter('now', $now);
    }
}
