<?php

declare(strict_types=1);

namespace App\Company\Infrastructure\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Enum\BusinessProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
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
        $company = $this->connection->fetchAssociative('SELECT user_id FROM companies WHERE id=?'.($lock ? ' FOR SHARE' : ''), [$companyId]);
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

    public function findByUserId(string $userId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('c.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
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
     * Возвращает список ID всех активных компаний в системе.
     * Используется воркерами и CLI-командами других модулей.
     *
     * @return list<string>
     */
    public function getAllActiveCompanyIds(): array
    {
        // До появления CompanyStatus/is_active все сохранённые companies считаются активными.
        $sql = 'SELECT id::text FROM companies ORDER BY id';

        return $this->connection->fetchFirstColumn($sql);
    }

    /**
     * Профили бизнеса всех компаний в порядке getAllActiveCompanyIds(): одним запросом, без
     * гидрации сущностей — для обхода всех компаний CLI-командами.
     *
     * @return array<string, BusinessProfile> id компании → профиль
     */
    public function getAllActiveCompanyProfiles(): array
    {
        $profiles = [];
        foreach ($this->connection->fetchAllKeyValue('SELECT id::text, business_profile FROM companies ORDER BY id') as $id => $profile) {
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
