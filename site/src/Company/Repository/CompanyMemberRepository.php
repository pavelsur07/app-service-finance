<?php

declare(strict_types=1);

namespace App\Company\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
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
     * Состояние активных участников для проверки инварианта «остался делегированный админ».
     *
     * Возвращает массивы, а не сущности: под блокировкой нужно свежее состояние из БД,
     * а объектный запрос вернул бы те же managed-инстансы, что уже лежат в identity map
     * после предварительной UX-проверки, то есть устаревшие.
     *
     * @return list<array{memberId: string, userId: string, memberRole: string, roleId: ?string, permissions: ?array<string, string>}>
     */
    public function findActiveAdminStateByCompany(Company $company): array
    {
        /** @var list<array{memberId: string, userId: string, memberRole: string, roleId: ?string, permissions: ?array<string, string>}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select(
                'm.id AS memberId',
                'IDENTITY(m.user) AS userId',
                'm.role AS memberRole',
                'IDENTITY(m.accessRole) AS roleId',
                'r.permissions AS permissions',
            )
            ->leftJoin('m.accessRole', 'r')
            ->andWhere('m.company = :company')
            ->andWhere('m.status = :active')
            ->setParameter('company', $company)
            ->setParameter('active', CompanyMember::STATUS_ACTIVE)
            ->getQuery()
            ->getArrayResult();

        return $rows;
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

    public function findOneByIdAndCompanyId(string $memberId, string $companyId): ?CompanyMember
    {
        return $this->createQueryBuilder('companyMember')
            ->innerJoin('companyMember.company', 'company')
            ->andWhere('companyMember.id = :memberId')
            ->andWhere('company.id = :companyId')
            ->setParameter('memberId', $memberId)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveOneByCompanyAndUser(Company $company, User $user): ?CompanyMember
    {
        return $this->createQueryBuilder('companyMember')
            ->andWhere('companyMember.company = :company')
            ->andWhere('companyMember.user = :user')
            ->andWhere('companyMember.status = :status')
            ->setParameter('company', $company)
            ->setParameter('user', $user)
            ->setParameter('status', CompanyMember::STATUS_ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countByAccessRole(CompanyRole $role): int
    {
        return (int) $this->createQueryBuilder('companyMember')
            ->andWhere('companyMember.accessRole = :role')
            ->setParameter('role', $role)
            ->select('COUNT(companyMember.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Активные членства пользователя во всех компаниях (кроме собственных —
     * там членства нет, компания видна через Company.user).
     *
     * @return list<CompanyMember>
     */
    public function findActiveByUserId(string $userId): array
    {
        /** @var list<CompanyMember> $result */
        $result = $this->createQueryBuilder('companyMember')
            ->innerJoin('companyMember.company', 'company')
            ->addSelect('company')
            ->innerJoin('companyMember.user', 'user')
            ->andWhere('user.id = :userId')
            ->andWhere('companyMember.status = :status')
            ->setParameter('userId', $userId)
            ->setParameter('status', CompanyMember::STATUS_ACTIVE)
            ->orderBy('company.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findFirstActiveCompanyForUser(User $user): ?Company
    {
        $companyMember = $this->createQueryBuilder('companyMember')
            ->innerJoin('companyMember.company', 'company')
            ->addSelect('company')
            ->andWhere('companyMember.user = :user')
            ->andWhere('companyMember.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', CompanyMember::STATUS_ACTIVE)
            ->orderBy('companyMember.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($companyMember instanceof CompanyMember) {
            return $companyMember->getCompany();
        }

        return null;
    }
}
