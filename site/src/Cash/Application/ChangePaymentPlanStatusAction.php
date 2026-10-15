<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Service\PaymentPlan\PaymentPlanService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Переводит плановый платёж в другой статус по правилам переходов.
 */
final class ChangePaymentPlanStatusAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentPlanService $paymentPlanService,
    ) {
    }

    /**
     * @throws \DomainException переход запрещён
     */
    public function __invoke(PaymentPlan $plan, string $targetStatus): void
    {
        $this->paymentPlanService->transitionStatus($plan, $targetStatus);
        $this->entityManager->flush();
    }
}
