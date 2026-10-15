<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\DTO\PaymentPlanDTO;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Service\PaymentPlan\PaymentPlanDtoApplier;
use App\Company\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Сохраняет изменения планового платежа из формы платёжного календаря.
 */
final class UpdatePaymentPlanAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentPlanDtoApplier $dtoApplier,
    ) {
    }

    public function __invoke(PaymentPlan $plan, Company $company, PaymentPlanDTO $dto): void
    {
        $this->dtoApplier->apply($plan, $company, $dto);

        $this->entityManager->flush();
    }
}
