<?php

declare(strict_types=1);

namespace App\Billing\Service\Admin;

use App\Billing\Entity\Plan;
use App\Billing\Entity\PlanFeature;
use App\Billing\Repository\PlanFeatureRepository;
use App\Billing\Repository\PlanRepository;

final class PlanAdminQueryService
{
    public function __construct(
        private readonly PlanRepository $planRepository,
        private readonly PlanFeatureRepository $planFeatureRepository,
    ) {
    }

    /**
     * @return Plan[]
     */
    public function getPlans(): array
    {
        return $this->planRepository->findAllOrdered();
    }

    public function getPlan(string $id): ?Plan
    {
        return $this->planRepository->find($id);
    }

    /**
     * @return PlanFeature[]
     */
    public function getPlanFeatures(Plan $plan): array
    {
        return $this->planFeatureRepository->findByPlan($plan);
    }
}
