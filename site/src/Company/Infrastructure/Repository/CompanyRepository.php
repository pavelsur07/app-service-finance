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
}
