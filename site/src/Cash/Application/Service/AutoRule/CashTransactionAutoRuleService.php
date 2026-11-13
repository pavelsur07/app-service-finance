<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\AutoRule;

use App\Cash\Application\DTO\CashTransactionAutoRuleApplicationPlan;
use App\Cash\Application\DTO\CashTransactionAutoRuleMatchResult;
use App\Cash\Application\DTO\CashTransactionAutoRulePreviewResult;
use App\Cash\Application\DTO\CashTransactionAutoRuleProvenance;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleApplyMode;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleSkipReason;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;

class CashTransactionAutoRuleService
{
    public function __construct(
        private readonly CashTransactionAutoRuleRepository $ruleRepo,
        private readonly CashAutoRuleMatcher $matcher,
        private readonly CashAutoRuleApplicationPlanner $planner,
        private readonly CashAutoRulePreviewBuilder $previewBuilder,
    ) {
    }

    /** @return array<string, mixed> */
    public function previewTransaction(CashTransaction $transaction): array
    {
        return $this->previewBuilder->forTransaction($transaction);
    }

    public function match(CashTransaction $t): CashTransactionAutoRuleMatchResult
    {
        if (null !== $this->getSkipReason($t)) {
            return new CashTransactionAutoRuleMatchResult(null);
        }

        return $this->matcher->match($t, array_values($this->ruleRepo->findActiveByCompany($t->getCompany())));
    }

    /** @param iterable<CashTransaction> $transactions */
    public function previewRule(
        CashTransactionAutoRule $rule,
        iterable $transactions,
        int $limit,
    ): CashTransactionAutoRulePreviewResult {
        return $this->previewBuilder->forRule($rule, $transactions, $limit);
    }

    /** Применить текущее правило-победитель к одной транзакции без сохранения. */
    public function applyRule(
        CashTransactionAutoRule $rule,
        CashTransaction $t,
        ?CashTransactionAutoRuleMatchResult $resolvedMatch = null,
    ): ?CashTransactionAutoRuleApplicationPlan {
        if (null !== $this->getSkipReason($t)) {
            return null;
        }

        if (!$rule->isActive() || !$this->matcher->matches($rule, $t)) {
            return null;
        }

        $resolvedMatch ??= $this->match($t);
        if (!$resolvedMatch->isWinner($rule)) {
            return null;
        }

        return $this->applyMatch($t, $resolvedMatch);
    }

    public function applyMatch(
        CashTransaction $transaction,
        ?CashTransactionAutoRuleMatchResult $resolvedMatch = null,
        CashTransactionAutoRuleApplyMode $mode = CashTransactionAutoRuleApplyMode::SAFE,
        ?CashTransactionAutoRuleProvenance $provenance = null,
    ): ?CashTransactionAutoRuleApplicationPlan {
        if (null !== $this->getSkipReason($transaction)) {
            return null;
        }

        $resolvedMatch ??= $this->match($transaction);
        if (!$resolvedMatch->hasWinners()) {
            return null;
        }

        $plan = $this->createApplicationPlan($resolvedMatch, $transaction, mode: $mode, provenance: $provenance);
        if (!$plan->hasChanges()) {
            return null;
        }

        $this->applyPlan($plan, $transaction);

        return $plan;
    }

    /** @param list<\App\Company\Application\DTO\FinancialResponsibilityCenterProjectDTO>|null $activePairs */
    public function createApplicationPlan(
        CashTransactionAutoRuleMatchResult $match,
        CashTransaction $t,
        ?array $activePairs = null,
        CashTransactionAutoRuleApplyMode $mode = CashTransactionAutoRuleApplyMode::SAFE,
        ?CashTransactionAutoRuleProvenance $provenance = null,
    ): CashTransactionAutoRuleApplicationPlan {
        return $this->planner->create($match, $t, $activePairs, $mode, $provenance);
    }

    private function applyPlan(CashTransactionAutoRuleApplicationPlan $plan, CashTransaction $transaction): void
    {
        if (array_key_exists('cashflowCategory', $plan->changes)) {
            $transaction->setCashflowCategory($plan->cashflowCategory);
        }

        if (array_key_exists('projectDirection', $plan->changes)) {
            $transaction->setProjectDirection($plan->projectDirection);
        }

        if (array_key_exists('responsibilityCenterId', $plan->changes)) {
            $transaction->setResponsibilityCenterId($plan->responsibilityCenterId);
        }

        if (array_key_exists('counterparty', $plan->changes)) {
            $transaction->setCounterparty($plan->counterparty);
        }
    }

    public function getSkipReason(CashTransaction $transaction): ?CashTransactionAutoRuleSkipReason
    {
        return $this->matcher->getSkipReason($transaction);
    }
}
