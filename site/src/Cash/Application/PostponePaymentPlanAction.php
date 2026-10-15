<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\PaymentPlan\PaymentPlan;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Переносит плановый платёж на заданное число дней. Платёж без даты не трогает.
 */
final class PostponePaymentPlanAction
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(PaymentPlan $plan, int $days): void
    {
        $plannedAt = $plan->getPlannedAt();
        if (null === $plannedAt) {
            return;
        }

        $plan->setPlannedAt($plannedAt->modify(sprintf('+%d day', $days)));
        $this->entityManager->flush();
    }
}
