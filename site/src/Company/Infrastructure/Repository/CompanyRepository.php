<?php

declare(strict_types=1);

namespace App\Company\Infrastructure\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

class CompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly Connection $connection)
    {
        parent::__construct($registry, Company::class);
    }

    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserId(string $userId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findById(string $companyId): ?Company
    {
        return $this->find($companyId);
    }

    public function findOneByName(string $name): ?Company
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Глобальный поиск используется только справочным MCP-инструментом.
     *
     * @return list<string>
     */
    public function findIdsByExactName(string $name): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id AS id')
            ->where('LOWER(c.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(2)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): string => (string) $row['id'],
            $rows,
        );
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
            ->orderBy('c.name', 'ASC')
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
