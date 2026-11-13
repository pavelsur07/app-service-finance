<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\AutoRule;

use App\Cash\Application\DTO\CashTransactionAutoRuleApplicationPlan;
use App\Cash\Application\DTO\CashTransactionAutoRuleMatchResult;
use App\Cash\Application\DTO\CashTransactionAutoRuleProvenance;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleApplyMode;
use App\Cash\Enum\Transaction\CashTransactionAutoRulePairIssue;
use App\Company\Application\DTO\FinancialResponsibilityCenterProjectDTO;
use App\Company\Facade\FinancialResponsibilityCenterFacade;

final readonly class CashAutoRuleApplicationPlanner
{
    public function __construct(
        private FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
    ) {
    }

    /** @param list<FinancialResponsibilityCenterProjectDTO>|null $activePairs */
    public function create(
        CashTransactionAutoRuleMatchResult $match,
        CashTransaction $transaction,
        ?array $activePairs = null,
        CashTransactionAutoRuleApplyMode $mode = CashTransactionAutoRuleApplyMode::SAFE,
        ?CashTransactionAutoRuleProvenance $provenance = null,
    ): CashTransactionAutoRuleApplicationPlan {
        $changes = [];
        $rulesByField = [];
        $categoryRule = $match->winners['cashflowCategory'] ?? null;
        $projectRule = $match->winners['projectDirection'] ?? null;
        $counterpartyRule = $match->winners['counterparty'] ?? null;
        $category = $categoryRule?->getCashflowCategory();
        $projectDirection = $projectRule?->getProjectDirection();
        $counterparty = $counterpartyRule?->getCounterparty();

        $currentCategory = $transaction->getCashflowCategory();
        $isCategoryEmpty = null === $currentCategory || in_array(
            $currentCategory->getCode(),
            [CashflowCategory::CODE_UNALLOCATED, CashflowCategory::SYSTEM_UNALLOCATED],
            true,
        );
        $canReplaceCategory = CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED === $mode
            && true === $provenance?->isAutoAssigned('cashflowCategory');
        $targetIsUnallocated = in_array(
            $category?->getCode(),
            [CashflowCategory::CODE_UNALLOCATED, CashflowCategory::SYSTEM_UNALLOCATED],
            true,
        );
        if (null !== $categoryRule && null !== $category
            && $currentCategory?->getId() !== $category->getId()
            && ($isCategoryEmpty || ($canReplaceCategory && !$targetIsUnallocated))) {
            $changes['cashflowCategory'] = [
                'before' => $currentCategory?->getId(),
                'after' => $category->getId(),
            ];
            $rulesByField['cashflowCategory'] = $categoryRule;
        }
        $activePairs ??= $this->matchNeedsPairPlan($match)
            ? $this->responsibilityCenterFacade->getActivePairs((string) $transaction->getCompany()->getId())
            : [];
        $pairPlan = $this->planPair($match, $transaction, $activePairs, $mode, $provenance);
        $changes += $pairPlan['changes'];
        $rulesByField += $pairPlan['rulesByField'];
        $projectDirection = $pairPlan['projectDirection'] ?? $projectDirection;
        if (null === $transaction->getCounterparty() && null !== $counterpartyRule && null !== $counterparty) {
            $changes['counterparty'] = [
                'before' => null,
                'after' => $counterparty->getId(),
            ];
            $rulesByField['counterparty'] = $counterpartyRule;
        }

        $primaryRule = $match->rule ?? $this->firstPairConflictingRule($match);
        if (null === $primaryRule) {
            throw new \LogicException('An application plan requires at least one winning auto rule.');
        }

        return new CashTransactionAutoRuleApplicationPlan(
            $primaryRule,
            $changes,
            $category,
            $projectDirection,
            $counterparty,
            rulesByField: $rulesByField,
            responsibilityCenterId: $pairPlan['responsibilityCenterId'],
            pairIssue: $pairPlan['issue'],
        );
    }

    /**
     * @param list<FinancialResponsibilityCenterProjectDTO> $activePairs
     *
     * @return array{
     *     changes: array<string, array{before: ?string, after: ?string}>,
     *     rulesByField: array<string, CashTransactionAutoRule>,
     *     projectDirection: ?\App\Company\Entity\ProjectDirection,
     *     responsibilityCenterId: ?string,
     *     issue: ?CashTransactionAutoRulePairIssue
     * }
     */
    private function planPair(
        CashTransactionAutoRuleMatchResult $match,
        CashTransaction $transaction,
        array $activePairs,
        CashTransactionAutoRuleApplyMode $mode,
        ?CashTransactionAutoRuleProvenance $provenance,
    ): array {
        $emptyPlan = [
            'changes' => [],
            'rulesByField' => [],
            'projectDirection' => $transaction->getProjectDirection(),
            'responsibilityCenterId' => $transaction->getResponsibilityCenterId(),
            'issue' => null,
        ];

        if ($this->hasPairConflict($match)) {
            $emptyPlan['issue'] = CashTransactionAutoRulePairIssue::CONFLICT;

            return $emptyPlan;
        }

        $projectRule = $match->winners['projectDirection'] ?? null;
        $responsibilityCenterRule = $match->winners['responsibilityCenterId'] ?? null;
        if (null === $projectRule && null === $responsibilityCenterRule) {
            return $emptyPlan;
        }

        $currentProject = $transaction->getProjectDirection();
        $currentProjectId = $currentProject?->getId();
        $currentResponsibilityCenterId = $transaction->getResponsibilityCenterId();
        $systemPair = $this->systemPair($activePairs);
        $isSystemPair = null !== $systemPair
            && $currentProjectId === $systemPair->projectDirectionId
            && $currentResponsibilityCenterId === $systemPair->responsibilityCenterId;
        $canReplacePair = CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED === $mode
            && true === $provenance?->isAutoAssigned('projectDirection')
            && $provenance->isAutoAssigned('responsibilityCenterId');

        if (null !== $currentProjectId && null !== $currentResponsibilityCenterId && !$isSystemPair && !$canReplacePair) {
            return $emptyPlan;
        }

        $protectedProject = $isSystemPair || $canReplacePair ? null : $currentProject;
        $protectedResponsibilityCenterId = $isSystemPair || $canReplacePair ? null : $currentResponsibilityCenterId;
        $resultProject = $protectedProject ?? $projectRule?->getProjectDirection();
        $resultResponsibilityCenterId = $protectedResponsibilityCenterId
            ?? $responsibilityCenterRule?->getResponsibilityCenterId();

        if (null === $resultProject || null === $resultResponsibilityCenterId) {
            $emptyPlan['issue'] = CashTransactionAutoRulePairIssue::INCOMPLETE;

            return $emptyPlan;
        }

        if (!$this->pairIsAllowed($activePairs, (string) $resultProject->getId(), $resultResponsibilityCenterId)) {
            $emptyPlan['issue'] = CashTransactionAutoRulePairIssue::UNAVAILABLE;

            return $emptyPlan;
        }

        $changes = [];
        $rulesByField = [];
        if ($currentProjectId !== $resultProject->getId() && null !== $projectRule) {
            $changes['projectDirection'] = [
                'before' => $currentProjectId,
                'after' => $resultProject->getId(),
            ];
            $rulesByField['projectDirection'] = $projectRule;
        }
        if ($currentResponsibilityCenterId !== $resultResponsibilityCenterId && null !== $responsibilityCenterRule) {
            $changes['responsibilityCenterId'] = [
                'before' => $currentResponsibilityCenterId,
                'after' => $resultResponsibilityCenterId,
            ];
            $rulesByField['responsibilityCenterId'] = $responsibilityCenterRule;
        }

        return [
            'changes' => $changes,
            'rulesByField' => $rulesByField,
            'projectDirection' => $resultProject,
            'responsibilityCenterId' => $resultResponsibilityCenterId,
            'issue' => null,
        ];
    }

    public function hasPairConflict(CashTransactionAutoRuleMatchResult $match): bool
    {
        return isset($match->conflicts['projectDirection'])
            || isset($match->conflicts['responsibilityCenterId']);
    }

    private function matchNeedsPairPlan(CashTransactionAutoRuleMatchResult $match): bool
    {
        return isset($match->winners['projectDirection'])
            || isset($match->winners['responsibilityCenterId'])
            || $this->hasPairConflict($match);
    }

    /** @param list<CashTransactionAutoRule> $rules */
    public function requiresPairSnapshot(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (null !== $rule->getProjectDirection() || null !== $rule->getResponsibilityCenterId()) {
                return true;
            }
        }

        return false;
    }

    private function firstPairConflictingRule(CashTransactionAutoRuleMatchResult $match): ?CashTransactionAutoRule
    {
        foreach (['projectDirection', 'responsibilityCenterId'] as $field) {
            if (isset($match->conflicts[$field][0])) {
                return $match->conflicts[$field][0];
            }
        }

        return null;
    }

    /** @param list<FinancialResponsibilityCenterProjectDTO> $activePairs */
    private function pairIsAllowed(array $activePairs, string $projectDirectionId, string $responsibilityCenterId): bool
    {
        foreach ($activePairs as $pair) {
            if ($pair->projectDirectionId === $projectDirectionId
                && $pair->responsibilityCenterId === $responsibilityCenterId) {
                return true;
            }
        }

        return false;
    }

    /** @param list<FinancialResponsibilityCenterProjectDTO> $activePairs */
    private function systemPair(array $activePairs): ?FinancialResponsibilityCenterProjectDTO
    {
        foreach ($activePairs as $pair) {
            if ($pair->system) {
                return $pair;
            }
        }

        return null;
    }
}
