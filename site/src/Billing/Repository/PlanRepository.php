<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Dto\PlanView;
use App\Billing\Enum\BillingPeriod;
use Doctrine\DBAL\Connection;

final class PlanRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return PlanView[]
     */
    public function findAllOrdered(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<SQL
            SELECT id,
                   code,
                   name,
                   price_amount,
                   price_currency,
                   billing_period,
                   is_active,
                   created_at
              FROM billing_plan
          ORDER BY created_at DESC, code ASC
        SQL);

        return array_map([$this, 'hydratePlanView'], $rows);
    }

    public function findOneById(string $id): ?PlanView
    {
        $row = $this->connection->fetchAssociative(<<<SQL
            SELECT id,
                   code,
                   name,
                   price_amount,
                   price_currency,
                   billing_period,
                   is_active,
                   created_at
              FROM billing_plan
             WHERE id = :id
        SQL, ['id' => $id]);

        if (false === $row) {
            return null;
        }

        return $this->hydratePlanView($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydratePlanView(array $row): PlanView
    {
        $createdAt = $row['created_at'];
        if ($createdAt instanceof \DateTimeInterface) {
            $createdAt = \DateTimeImmutable::createFromInterface($createdAt);
        } else {
            $createdAt = new \DateTimeImmutable((string) $createdAt);
        }

        return new PlanView(
            (string) $row['id'],
            (string) $row['code'],
            (string) $row['name'],
            (int) $row['price_amount'],
            (string) $row['price_currency'],
            BillingPeriod::from((string) $row['billing_period']),
            (bool) $row['is_active'],
            $createdAt,
        );
    }
}
