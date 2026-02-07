<?php

declare(strict_types=1);

namespace App\Cash\Service\PaymentPlan;

use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Company\Entity\Company;
use App\DTO\PaymentPlanDTO;
use App\Enum\PaymentPlanStatus as PaymentPlanStatusEnum;
use App\Enum\PaymentPlanType as PaymentPlanTypeEnum;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class PaymentCalendarFacade
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PaymentPlanService $paymentPlanService,
    ) {
    }

    public function createPlanFromDto(Company $company, PaymentPlanDTO $dto): PaymentPlan
    {
        $plan = new PaymentPlan(
            Uuid::uuid4()->toString(),
            $company,
            $dto->cashflowCategory,
            \DateTimeImmutable::createFromInterface($dto->plannedAt),
            (string) $dto->amount
        );

        $this->applyDtoToPlan($plan, $company, $dto);

        $this->entityManager->persist($plan);
        $this->entityManager->flush();

        return $plan;
    }

    public function updatePlanFromDto(PaymentPlan $plan, Company $company, PaymentPlanDTO $dto): void
    {
        $this->applyDtoToPlan($plan, $company, $dto);

        $this->entityManager->flush();
    }

    public function changeStatus(PaymentPlan $plan, string $targetStatus): void
    {
        $this->paymentPlanService->transitionStatus($plan, $targetStatus);
        $this->entityManager->flush();
    }

    public function postpone(PaymentPlan $plan, int $days): void
    {
        $plannedAt = $plan->getPlannedAt();
        if (null === $plannedAt) {
            return;
        }

        $plan->setPlannedAt($plannedAt->modify(sprintf('+%d day', $days)));
        $this->entityManager->flush();
    }

    private function applyDtoToPlan(PaymentPlan $plan, Company $company, PaymentPlanDTO $dto): void
    {
        $this->paymentPlanService->applyCompanyScope($plan, $company);
        $plan->setCashflowCategory($dto->cashflowCategory);
        $plan->setPlannedAt(\DateTimeImmutable::createFromInterface($dto->plannedAt));
        $plan->setAmount((string) $dto->amount);
        $plan->setMoneyAccount($dto->moneyAccount);
        $plan->setCounterparty($dto->counterparty);
        $plan->setComment($dto->comment);

        $resolvedType = $this->paymentPlanService->resolveTypeByCategory($dto->cashflowCategory);
        $plan->setType(PaymentPlanTypeEnum::from($resolvedType));
        $plan->setStatus($this->resolveStatus($dto->status));
    }

    private function resolveStatus(?string $status): PaymentPlanStatusEnum
    {
        if (null === $status || '' === $status) {
            return PaymentPlanStatusEnum::PLANNED;
        }

        return PaymentPlanStatusEnum::from($status);
    }
}
