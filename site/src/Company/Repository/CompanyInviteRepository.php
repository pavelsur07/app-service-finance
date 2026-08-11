<?php

namespace App\Company\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyInvite;
use App\Company\Entity\CompanyRole;
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
     * Все приглашения, ссылающиеся на шаблон, а не только активные.
     *
     * Считать нужно ровно то, что запрещает FK `ON DELETE RESTRICT`, иначе сообщение
     * «назначен активным приглашениям» разойдётся с реальным отказом базы.
     * Терминальные приглашения ссылку освобождают сами (см. CompanyInvite::accept/revoke),
     * поэтому здесь остаются только живые и просроченные.
     */
    /**
     * Снимает ссылку на шаблон у приглашений, которые уже нельзя принять: принятых, отозванных
     * и просроченных.
     *
     * Истечение срока происходит само собой, без вызова revoke(), поэтому просроченное
     * приглашение иначе держало бы FK `ON DELETE RESTRICT` вечно и навсегда запрещало бы
     * удалить шаблон, хотя применить его уже невозможно.
     */
    public function releaseAccessRoleFromUnusableInvites(CompanyRole $role, \DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('invite')
            ->update()
            ->set('invite.accessRole', ':null')
            ->andWhere('invite.accessRole = :role')
            ->andWhere('invite.acceptedAt IS NOT NULL OR invite.revokedAt IS NOT NULL OR invite.expiresAt <= :now')
            ->setParameter('null', null)
            ->setParameter('role', $role)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }

    public function countByAccessRole(CompanyRole $role): int
    {
        return (int) $this->createQueryBuilder('invite')
            ->andWhere('invite.accessRole = :role')
            ->setParameter('role', $role)
            ->select('COUNT(invite.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPendingByAccessRole(CompanyRole $role, \DateTimeImmutable $now): int
    {
        return (int) $this->createPendingInviteQueryBuilder($now)
            ->andWhere('invite.accessRole = :role')
            ->setParameter('role', $role)
            ->select('COUNT(invite.id)')
            ->getQuery()
            ->getSingleScalarResult();
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
