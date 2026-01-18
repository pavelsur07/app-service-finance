<?php

namespace App\Cash\Service\PaymentPlan;

use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\PaymentPlan\PaymentPlanMatchRepository;
use App\Cash\Repository\PaymentPlan\PaymentPlanRepository;
use App\Entity\PaymentPlanMatch;
use App\Enum\PaymentPlanStatus as PaymentPlanStatusEnum;
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
        $existingMatch = $this->paymentPlanMatchRepository->findOneByTransaction($transaction);
        if (null !== $existingMatch) {
            return $existingMatch->getPlan();
        }

        $company = $transaction->getCompany();
        $txDate = $transaction->getOccurredAt();
        $from = $txDate->sub(new \DateInterval('P1D'));
        $to = $txDate->add(new \DateInterval('P1D'));

        $qb = $this->paymentPlanRepository->createQueryBuilder('plan')
            ->leftJoin(PaymentPlanMatch::class, 'match', 'WITH', 'match.plan = plan')
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

        $txAmount = abs((float) $transaction->getAmount());
        $txCategory = $transaction->getCashflowCategory();
        $txCounterparty = $transaction->getCounterparty();

        $best = null;

        foreach ($candidates as $plan) {
            $planAmount = abs((float) $plan->getAmount());
            $amountDiff = abs($planAmount - $txAmount);

            if ($planAmount > 0.0) {
                $relativeError = $amountDiff / $planAmount;
                if ($relativeError > 0.05) {
                    continue;
                }
            } elseif ($amountDiff > 0.0) {
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

        $this->em->persist($match);
        $this->paymentPlanService->transitionStatus($bestPlan, PaymentPlanStatusEnum::PAID->value);
        $this->em->flush();

        return $bestPlan;
    }
}
