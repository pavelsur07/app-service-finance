<?php

declare(strict_types=1);

namespace App\Company\Infrastructure\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Company\Enum\BusinessProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class CompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly Connection $connection)
    {
        parent::__construct($registry, Company::class);
    }

    /** @return array{owner:bool,read:bool,write:bool} */
    public function financialAccess(string $companyId, string $userId, bool $lock = false): array
    {
        if ($lock && !$this->connection->isTransactionActive()) {
            throw new \LogicException('Company permission locks require a transaction.');
        }
        $company = $this->connection->fetchAssociative('SELECT user_id FROM companies WHERE id=? AND deletion_requested_at IS NULL'.($lock ? ' FOR SHARE' : ''), [$companyId]);
        $none = ['owner' => false, 'read' => false, 'write' => false];
        if (false === $company) {
            return $none;
        }
        if ($company['user_id'] === $userId) {
            return ['owner' => true, 'read' => true, 'write' => true];
        }
        $member = $this->connection->fetchAssociative("SELECT m.role,r.company_id AS role_company_id,r.permissions FROM company_members m LEFT JOIN company_role r ON r.id=m.role_id WHERE m.company_id=? AND m.user_id=? AND m.status='ACTIVE'", [$companyId, $userId]);
        if (false === $member) {
            return $none;
        }
        if ('OWNER' === $member['role']) {
            return ['owner' => false, 'read' => true, 'write' => true];
        }
        if (null !== $member['role_company_id'] && $member['role_company_id'] !== $companyId) {
            return $none;
        }
        $permissions = null === $member['permissions'] ? [] : json_decode((string) $member['permissions'], true, 512, \JSON_THROW_ON_ERROR);
        $level = is_array($permissions) ? ($permissions['finance'] ?? null) : null;

        return ['owner' => false, 'read' => in_array($level, ['read', 'write'], true), 'write' => 'write' === $level];
    }

    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /** @companyScopeExempt Company discovery before selection; restricted to the authenticated user ID. */
    public function createAccessibleForUserQueryBuilder(string $userId): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->distinct()
            ->leftJoin(CompanyMember::class, 'membership', 'ON', 'membership.company = c AND membership.user = :accessUser AND membership.status = :active')
            ->andWhere('c.user = :accessUser OR membership.id IS NOT NULL')
            ->andWhere('c.deletionRequestedAt IS NULL')
            ->setParameter('accessUser', $userId)
            ->setParameter('active', CompanyMember::STATUS_ACTIVE)
            ->orderBy('c.name', \SortDirection::Ascending)
            ->addOrderBy('c.id', \SortDirection::Ascending);
    }

    /**
     * @return list<Company>
     *
     * @companyScopeExempt Discovery before company selection, restricted to the current user.
     */
    public function findAccessibleByUserId(string $userId): array
    {
        /** @var list<Company> $companies */
        $companies = $this->createAccessibleForUserQueryBuilder($userId)->getQuery()->getResult();

        return $companies;
    }

    public function isAccessibleByUser(string $companyId, string $userId): bool
    {
        return (int) $this->createAccessibleForUserQueryBuilder($userId)
            ->distinct(false)
            ->resetDQLPart('orderBy')
            ->select('COUNT(DISTINCT c.id)')
            ->andWhere('c.id = :targetCompany')
            ->setParameter('targetCompany', $companyId)
            ->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Компании владельца, помеченные к удалению, — для блока «Будет удалена» в списке.
     *
     * @return list<Company>
     */
    public function findDeletionRequestedByUser(User $user): array
    {
        /** @var list<Company> $companies */
        $companies = $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.deletionRequestedAt IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('c.deletionRequestedAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $companies;
    }

    /**
     * Работающие (не помеченные к удалению) компании владельца.
     *
     * @return list<Company>
     */
    public function findActiveByUser(User $user): array
    {
        /** @var list<Company> $companies */
        $companies = $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.deletionRequestedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('c.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $companies;
    }

    /** Компания по id, только если ею владеет пользователь (в т.ч. помеченная к удалению). */
    public function findOneOwnedBy(string $companyId, User $owner): ?Company
    {
        /** @var Company|null $company */
        $company = $this->findOneBy(['id' => $companyId, 'user' => $owner]);

        return $company;
    }

    public function findByUserId(string $userId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('c.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function getFinanceLockBefore(string $companyId): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne('SELECT finance_lock_before FROM companies WHERE id = ?', [$companyId]);

        return false === $value || null === $value ? null : new \DateTimeImmutable((string) $value);
    }

    /** Maintenance serializes all financial writers before source and book locks. */
    public function lockFinancialMaintenance(string $companyId): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Maintenance requires transaction.');
        }
        if (false === $this->connection->fetchOne('SELECT id FROM companies WHERE id=? FOR UPDATE', [$companyId])) {
            throw new \DomainException('Компания не найдена.');
        }
    }

    public function findById(string $companyId): ?Company
    {
        return $this->find($companyId);
    }

    /**
     * Global identity resolution only; callers must authorize the resolved company.
     */
    public function findByPublicId(int $publicId): ?Company
    {
        /** @var Company|null $company */
        $company = $this->findOneBy(['publicId' => $publicId]);

        return $company;
    }

    public function findOneByName(string $name): ?Company
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Возвращает список ID всех активных (не помеченных к удалению) компаний в системе.
     * Используется воркерами и CLI-командами других модулей.
     *
     * @return list<string>
     */
    public function getAllActiveCompanyIds(): array
    {
        // Помеченные к удалению компании не обрабатываются фоновыми задачами.
        $sql = 'SELECT id::text FROM companies WHERE deletion_requested_at IS NULL ORDER BY id';

        return $this->connection->fetchFirstColumn($sql);
    }

    /**
     * @companyScopeExempt обход всех компаний: фоновые задачи пропускают помеченные к удалению
     *
     * @return list<string>
     */
    public function getDeletionRequestedCompanyIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT id::text FROM companies WHERE deletion_requested_at IS NOT NULL ORDER BY id');

        return $ids;
    }

    /**
     * Профили бизнеса всех работающих компаний в порядке getAllActiveCompanyIds(): одним запросом, без
     * гидрации сущностей — для обхода всех компаний CLI-командами.
     *
     * @return array<string, BusinessProfile> id компании → профиль
     */
    public function getAllActiveCompanyProfiles(): array
    {
        $profiles = [];
        foreach ($this->connection->fetchAllKeyValue('SELECT id::text, business_profile FROM companies WHERE deletion_requested_at IS NULL ORDER BY id') as $id => $profile) {
            $profiles[(string) $id] = BusinessProfile::from((string) $profile);
        }

        return $profiles;
    }

    /**
     * @param list<string> $companyIds
     *
     * @return list<array{id: string, name: string}>
     */
    public function findByIds(array $companyIds): array
    {
        if ([] === $companyIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.id AS id', 'c.name AS name')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', array_values($companyIds))
            ->orderBy('c.name', \SortDirection::Ascending)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $r): array => [
                'id' => (string) $r['id'],
                'name' => (string) $r['name'],
            ],
            $rows,
        );
    }
}
