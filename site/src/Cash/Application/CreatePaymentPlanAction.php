<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\DTO\PaymentPlanDTO;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Service\PaymentPlan\PaymentPlanDtoApplier;
use App\Company\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Создаёт плановый платёж из формы платёжного календаря.
 */
final class CreatePaymentPlanAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentPlanDtoApplier $dtoApplier,
    ) {
    }

    public function __invoke(Company $company, PaymentPlanDTO $dto): PaymentPlan
    {
        $plan = new PaymentPlan(
            Uuid::uuid4()->toString(),
            $company,
            $dto->cashflowCategory,
            \DateTimeImmutable::createFromInterface($dto->plannedAt),
            (string) $dto->amount
        );

        $this->dtoApplier->apply($plan, $company, $dto);

        $this->entityManager->persist($plan);
        $this->entityManager->flush();

        return $plan;
    }
}
