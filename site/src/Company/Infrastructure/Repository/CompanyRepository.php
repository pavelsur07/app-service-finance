<?php

namespace App\Company\Infrastructure\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

class CompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry,  private readonly Connection $connection)
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

    function findById(string $companyId): ?Company
    {
        return $this->find($companyId);
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
        // В фасаде мы можем использовать прямой DBAL запрос для скорости (Fast Read),
        // либо вызвать внутренний CompanyQuery класс модуля Company.
        $sql = 'SELECT id::text FROM company WHERE is_active = true';

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
