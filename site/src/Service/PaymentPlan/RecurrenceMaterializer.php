<?php

namespace App\Service\PaymentPlan;

use App\Entity\Company;
use App\Entity\PaymentPlan;
use App\Enum\PaymentPlanStatus as PaymentPlanStatusEnum;
use App\Enum\PaymentPlanType as PaymentPlanTypeEnum;
use App\Repository\PaymentPlanRepository;
use App\Repository\PaymentRecurrenceRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class RecurrenceMaterializer
{
    public function __construct(
        private readonly PaymentRecurrenceRuleRepository $recurrenceRuleRepository,
        private readonly PaymentPlanRepository $paymentPlanRepository,
        private readonly PaymentRecurrenceService $paymentRecurrenceService,
        private readonly PaymentPlanService $paymentPlanService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function materialize(Company $company, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        $rangeStart = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0);
        $rangeEnd = \DateTimeImmutable::createFromInterface($to)->setTime(0, 0);

        if ($rangeEnd < $rangeStart) {
            return 0;
        }

        $rules = $this->recurrenceRuleRepository->findActiveByCompany($company);
        if ([] === $rules) {
            return 0;
        }

        $created = 0;

        foreach ($rules as $rule) {
            $seed = $this->paymentPlanRepository->findTemplateForRecurrenceRule($rule);

            if (!$seed instanceof PaymentPlan) {
                continue;
            }

            $occurrences = $this->paymentRecurrenceService->expandOccurrences($seed, $rangeStart, $rangeEnd);

            foreach ($occurrences as $occurrence) {
                $plannedAt = \DateTimeImmutable::createFromInterface($occurrence->getPlannedAt())->setTime(0, 0);
                $amount = (string) $occurrence->getAmount();
                $category = $occurrence->getCashflowCategory();

                if ($this->paymentPlanRepository->existsRecurrenceDuplicate($company, $rule, $plannedAt, $amount, $category)) {
                    continue;
                }

                $plan = new PaymentPlan(
                    Uuid::uuid4()->toString(),
                    $company,
                    $category,
                    $plannedAt,
                    $amount
                );

                $plan->setMoneyAccount($occurrence->getMoneyAccount());
                $plan->setCounterparty($occurrence->getCounterparty());
                $plan->setComment($occurrence->getComment());
                $plan->setRecurrenceRule($rule);
                $plan->setPlannedAt($plannedAt);
                $plan->setAmount($amount);

                $resolvedType = $this->paymentPlanService->resolveTypeByCategory($category);
                $plan->setType(PaymentPlanTypeEnum::from($resolvedType));
                $plan->setStatus(PaymentPlanStatusEnum::PLANNED);

                $this->entityManager->persist($plan);
                $created++;
            }
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return $created;
    }
}
