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

        $best = null;

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

            $dateDiff = (int) $plan->getPlannedAt()->diff($txDate)->format('%a');

            $candidateData = [
                'plan' => $plan,
                'score' => $score,
                'amountDiff' => $amountDiff,
                'dateDiff' => $dateDiff,
            ];

            if (null === $best) {
                $best = $candidateData;

                continue;
            }

            if ($candidateData['score'] > $best['score']) {
                $best = $candidateData;

                continue;
            }

            if ($candidateData['score'] < $best['score']) {
                continue;
            }

            if ($candidateData['amountDiff'] < $best['amountDiff']) {
                $best = $candidateData;

                continue;
            }

            if ($candidateData['amountDiff'] > $best['amountDiff']) {
                continue;
            }

            if ($candidateData['dateDiff'] < $best['dateDiff']) {
                $best = $candidateData;
            }
        }

        if (null === $best) {
            return null;
        }

        /** @var PaymentPlan $bestPlan */
        $bestPlan = $best['plan'];

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
