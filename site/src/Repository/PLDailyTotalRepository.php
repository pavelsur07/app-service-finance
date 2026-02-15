<?php

namespace App\Repository;

use App\Company\Entity\Company;
use App\Entity\PLDailyTotal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

class PLDailyTotalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PLDailyTotal::class);
    }


    public function maxUpdatedAtForCompany(Company $company): ?\DateTimeImmutable
    {
        $maxUpdatedAt = $this->createQueryBuilder('t')
            ->select('MAX(t.updatedAt)')
            ->andWhere('t.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleScalarResult();

        if (!$maxUpdatedAt instanceof \DateTimeInterface) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($maxUpdatedAt);
    }


    public function maxUpdatedAtGlobal(): ?\DateTimeImmutable
    {
        $maxUpdatedAt = $this->createQueryBuilder('t')
            ->select('MAX(t.updatedAt)')
            ->getQuery()
            ->getSingleScalarResult();

        if (!$maxUpdatedAt instanceof \DateTimeInterface) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($maxUpdatedAt);
    }

    public function upsert(
        string $companyId,
        ?string $categoryId,
        \DateTimeImmutable $date,
        string $projectDirectionId,
        string $amountIncome,
        string $amountExpense,
        bool $replace,
        ?\DateTimeImmutable $timestamp = null,
    ): void {
        $timestamp ??= new \DateTimeImmutable();

        $connection = $this->getEntityManager()->getConnection();

        $sql = sprintf(
            <<<'SQL'
INSERT INTO pl_daily_totals (id, company_id, pl_category_id, date, project_direction_id, amount_income, amount_expense, created_at, updated_at)
VALUES (:id, :company_id, :category_id, :date, :project_direction_id, :amount_income, :amount_expense, :created_at, :updated_at)
ON CONFLICT (company_id, pl_category_id, date, project_direction_id) DO UPDATE SET
    amount_income = %s,
    amount_expense = %s,
    updated_at = EXCLUDED.updated_at
SQL,
            $replace ? 'EXCLUDED.amount_income' : 'pl_daily_totals.amount_income + EXCLUDED.amount_income',
            $replace ? 'EXCLUDED.amount_expense' : 'pl_daily_totals.amount_expense + EXCLUDED.amount_expense',
        );

        $connection->executeStatement(
            $sql,
            [
                'id' => Uuid::uuid4()->toString(),
                'company_id' => $companyId,
                'category_id' => $categoryId,
                'date' => $date,
                'project_direction_id' => $projectDirectionId,
                'amount_income' => $amountIncome,
                'amount_expense' => $amountExpense,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => Types::GUID,
                'company_id' => Types::GUID,
                'category_id' => Types::GUID,
                'date' => Types::DATE_IMMUTABLE,
                'project_direction_id' => Types::GUID,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }
}
