<?php

declare(strict_types=1);

namespace App\Cash\Service\PaymentPlan;

use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\PaymentPlan\PaymentPlanMatch;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\PaymentPlan\PaymentPlanStatus as PaymentPlanStatusEnum;
use App\Cash\Repository\PaymentPlan\PaymentPlanMatchRepository;
use App\Cash\Repository\PaymentPlan\PaymentPlanRepository;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class PaymentPlanMatcher
{
    public function __construct(
        private EntityManagerInterface $em,
        private PaymentPlanRepository $paymentPlanRepository,
        private PaymentPlanMatchRepository $paymentPlanMatchRepository,
        private PaymentPlanService $paymentPlanService,
    ) {
    }

    public function matchForTransaction(CashTransaction $transaction): ?PaymentPlan
    {
        $existingMatch = $this->paymentPlanMatchRepository->findOneByTransactionAndCompanyId(
            $transaction,
            (string) $transaction->getCompany()->getId(),
        );
        if (null !== $existingMatch) {
            return $existingMatch->getPlan();
        }

        if (FiatCurrency::RUB->value !== $transaction->getCurrency()) {
            return null;
        }

        $company = $transaction->getCompany();
        $txDate = $transaction->getOccurredAt();
        $from = $txDate->sub(new \DateInterval('P1D'));
        $to = $txDate->add(new \DateInterval('P1D'));

        $qb = $this->paymentPlanRepository->createQueryBuilder('plan')
            ->leftJoin(PaymentPlanMatch::class, 'match', 'ON', 'match.plan = plan')
            ->where('plan.company = :company')
            ->andWhere('plan.plannedAt BETWEEN :from AND :to')
            ->andWhere('match.id IS NULL')
            ->andWhere('plan.status NOT IN (:excludedStatuses)')
            ->setParameter('company', $company)
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->setParameter(
                'excludedStatuses',
                [PaymentPlanStatusEnum::PAID->value, PaymentPlanStatusEnum::CANCELED->value],
                ArrayParameterType::STRING,
            );

        /** @var list<PaymentPlan> $candidates */
        $candidates = $qb->getQuery()->getResult();

        // Целые минорные единицы, не float: и допуск 5%, и выбор кандидата по
        // разнице сумм сравниваются точно — равные разницы не расходятся в
        // последнем знаке. Сверка только для RUB (проверено выше), план — RUB-only.
        $txAmount = Money::fromString($transaction->getAmount(), FiatCurrency::RUB->value)->abs()->amountMinor();
        // Решение D2: мультиразбивка исключена из автомэтча. PaymentPlanMatch уникален
        // по transaction_id, поэтому сопоставить план со строкой нельзя без смены модели,
        // а сопоставлять со всей транзакцией, разнесённой по разным статьям, бессмысленно.
        $splits = $transaction->getSplits();
        if ($splits->count() > 1) {
            return null;
        }

        $txCategory = $transaction->getSingleSplitCategory();
        $txCounterparty = $transaction->getCounterparty();

        $ranked = [];

        foreach ($candidates as $plan) {
            $planAmount = Money::fromString($plan->getAmount(), FiatCurrency::RUB->value)->abs()->amountMinor();
            $amountDiff = abs($planAmount - $txAmount);

            if ($planAmount > 0) {
                // amountDiff / planAmount > 5% без деления.
                if ($amountDiff * 20 > $planAmount) {
                    continue;
                }
            } elseif ($amountDiff > 0) {
                continue;
            }

            $score = 0;
            if (null !== $txCategory && $plan->getCashflowCategory()?->getId() === $txCategory->getId()) {
                ++$score;
            }
            if (null !== $txCounterparty && $plan->getCounterparty()?->getId() === $txCounterparty->getId()) {
                ++$score;
            }

            $ranked[] = [
                'plan' => $plan,
                'score' => $score,
                'amountDiff' => $amountDiff,
                'dateDiff' => (int) $plan->getPlannedAt()->diff($txDate)->format('%a'),
            ];
        }

        // Лучший — больше совпадений (статья, контрагент), затем меньше разница сумм, затем дат;
        // при равенстве — порядок выборки (usort стабилен).
        usort($ranked, static fn (array $a, array $b): int => [$b['score'], $a['amountDiff'], $a['dateDiff']]
            <=> [$a['score'], $b['amountDiff'], $b['dateDiff']]);

        // Выборка кандидатов — без блокировки: план, который параллельная транзакция уже заняла,
        // под FOR UPDATE оказывается сопоставленным — берётся следующий. Один план — одно
        // сопоставление (уникальный индекс по plan_id — страховка).
        $bestPlan = null;
        foreach ($ranked as $candidate) {
            if ($this->paymentPlanRepository->lockIfAvailableForMatch(
                (string) $candidate['plan']->getId(),
                (string) $company->getId(),
            )) {
                $bestPlan = $candidate['plan'];
                break;
            }
        }

        if (null === $bestPlan) {
            return null;
        }

        $match = new PaymentPlanMatch(
            Uuid::uuid4()->toString(),
            $company,
            $bestPlan,
            $transaction,
            new \DateTimeImmutable(),
        );

        // Без flush(): совпадение и статус плана сохраняет вызывающий Action.
        $this->em->persist($match);
        $this->paymentPlanService->transitionStatus($bestPlan, PaymentPlanStatusEnum::PAID->value);

        return $bestPlan;
    }
}
