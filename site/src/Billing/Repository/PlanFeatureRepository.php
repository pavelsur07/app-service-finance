<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Dto\PlanFeatureView;
use App\Billing\Dto\PlanView;
use Doctrine\DBAL\Connection;

final class PlanFeatureRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return PlanFeatureView[]
     */
    public function findByPlan(PlanView $plan): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<SQL
            SELECT feature.code AS feature_code,
                   plan_feature.value,
                   plan_feature.soft_limit,
                   plan_feature.hard_limit
              FROM billing_plan_feature AS plan_feature
              INNER JOIN billing_feature AS feature ON plan_feature.feature_id = feature.id
             WHERE plan_feature.plan_id = :planId
          ORDER BY feature.code ASC
        SQL, ['planId' => $plan->getId()]);

        return array_map(
            static fn (array $row): PlanFeatureView => new PlanFeatureView(
                (string) $row['feature_code'],
                (string) $row['value'],
                null === $row['soft_limit'] ? null : (int) $row['soft_limit'],
                null === $row['hard_limit'] ? null : (int) $row['hard_limit'],
            ),
            $rows,
        );
    }
}
