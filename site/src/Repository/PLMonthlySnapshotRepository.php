<?php

namespace App\Repository;

use App\Entity\PLMonthlySnapshot;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

class PLMonthlySnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PLMonthlySnapshot::class);
    }

    public function upsert(
        string $companyId,
        ?string $categoryId,
        string $period,
        string $amountIncome,
        string $amountExpense,
        ?DateTimeImmutable $updatedAt = null,
    ): void {
        $updatedAt ??= new DateTimeImmutable();

        $connection = $this->getEntityManager()->getConnection();

        $sql = <<<'SQL'
INSERT INTO pl_monthly_snapshots (id, company_id, pl_category_id, period, amount_income, amount_expense, updated_at)
VALUES (:id, :company_id, :category_id, :period, :amount_income, :amount_expense, :updated_at)
ON CONFLICT (company_id, pl_category_id, period) DO UPDATE SET
    amount_income = EXCLUDED.amount_income,
    amount_expense = EXCLUDED.amount_expense,
    updated_at = EXCLUDED.updated_at
SQL;

        $connection->executeStatement(
            $sql,
            [
                'id' => Uuid::uuid4()->toString(),
                'company_id' => $companyId,
                'category_id' => $categoryId,
                'period' => $period,
                'amount_income' => $amountIncome,
                'amount_expense' => $amountExpense,
                'updated_at' => $updatedAt,
            ],
            [
                'id' => Types::GUID,
                'company_id' => Types::GUID,
                'category_id' => Types::GUID,
                'period' => Types::STRING,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }
}
