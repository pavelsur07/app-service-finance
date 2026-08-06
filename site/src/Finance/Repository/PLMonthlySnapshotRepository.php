<?php

declare(strict_types=1);

namespace App\Finance\Repository;

use App\Finance\Entity\PLMonthlySnapshot;
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
        ?\DateTimeImmutable $updatedAt = null,
        bool $accumulate = false,
    ): void {
        $updatedAt ??= new \DateTimeImmutable();

        $connection = $this->getEntityManager()->getConnection();

        $sql = <<<'SQL'
INSERT INTO pl_monthly_snapshots (id, company_id, pl_category_id, period, amount_income, amount_expense, updated_at)
VALUES (:id, :company_id, :category_id, :period, :amount_income, :amount_expense, :updated_at)
ON CONFLICT (company_id, pl_category_id, period) DO UPDATE SET
    amount_income = %s,
    amount_expense = %s,
    updated_at = EXCLUDED.updated_at
SQL;
        $sql = sprintf(
            $sql,
            $accumulate ? 'pl_monthly_snapshots.amount_income + EXCLUDED.amount_income' : 'EXCLUDED.amount_income',
            $accumulate ? 'pl_monthly_snapshots.amount_expense + EXCLUDED.amount_expense' : 'EXCLUDED.amount_expense',
        );

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
