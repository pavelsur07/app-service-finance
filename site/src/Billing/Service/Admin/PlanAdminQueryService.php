<?php

declare(strict_types=1);

namespace App\Billing\Service\Admin;

use App\Billing\Dto\PlanFeatureView;
use App\Billing\Dto\PlanView;
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
     * @return PlanView[]
     */
    public function listPlans(): array
    {
        return $this->planRepository->findAllOrdered();
    }

    public function getPlan(string $id): ?PlanView
    {
        return $this->planRepository->findOneById($id);
    }

    /**
     * @return PlanFeatureView[]
     */
    public function listPlanFeatures(PlanView $plan): array
    {
        return $this->planFeatureRepository->findByPlan($plan);
    }
}
