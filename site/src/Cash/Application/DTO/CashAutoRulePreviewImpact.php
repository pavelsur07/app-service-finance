<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleSkipReason;

/** Счётчики по всей выборке и отдельная ограниченная подборка примеров для каждого результата. */
final class CashAutoRulePreviewImpact
{
    public const FIELDS = [
        'cashflowCategory' => 'Статья',
        'projectDirection' => 'Проект',
        'responsibilityCenterId' => 'ЦФО',
        'counterparty' => 'Контрагент',
    ];

    /** @var array<string, array{count: int, examples: list<array{transaction: CashTransaction, detail: string}>}> */
    public array $metrics = [];

    public function record(string $key, CashTransaction $transaction, string $detail = ''): void
    {
        $this->metrics[$key] ??= ['count' => 0, 'examples' => []];
        ++$this->metrics[$key]['count'];
        if (count($this->metrics[$key]['examples']) < 5) {
            $this->metrics[$key]['examples'][] = ['transaction' => $transaction, 'detail' => $detail];
        }
    }

    /** @param array<string, string> $centers */
    public function compare(
        CashTransaction $transaction,
        CashTransactionAutoRuleMatchResult $before,
        CashTransactionAutoRuleMatchResult $after,
        ?CashTransactionAutoRuleApplicationPlan $beforePlan,
        ?CashTransactionAutoRuleApplicationPlan $afterPlan,
        ?CashTransactionAutoRuleSkipReason $skipReason,
        array $centers,
    ): void {
        if (null !== $skipReason) {
            $this->record($skipReason->value, $transaction, $skipReason->label());

            return;
        }

        $oldCategory = isset($beforePlan->changes['cashflowCategory']) ? $beforePlan->cashflowCategory : $transaction->getCashflowCategory();
        $newCategory = isset($afterPlan->changes['cashflowCategory']) ? $afterPlan->cashflowCategory : $transaction->getCashflowCategory();
        if (!$this->hasCategory($oldCategory) && $this->hasCategory($newCategory)) {
            $this->record('newCategory', $transaction, (string) $newCategory?->getName());
        }

        foreach (array_keys(self::FIELDS) as $field) {
            $oldWinner = $before->winners[$field] ?? null;
            $newWinner = $after->winners[$field] ?? null;
            $oldConflicts = $before->conflicts[$field] ?? [];
            $newConflicts = $after->conflicts[$field] ?? [];
            if ($oldWinner?->getId() !== $newWinner?->getId()) {
                $this->record('winner.'.$field, $transaction, ($oldWinner?->getName() ?? '—').' → '.($newWinner?->getName() ?? '—'));
            }
            if ([] === $oldConflicts && [] !== $newConflicts) {
                $this->record('conflictAdded.'.$field, $transaction, $this->names($newConflicts));
            }
            if ([] !== $oldConflicts && [] === $newConflicts) {
                $this->record('conflictRemoved.'.$field, $transaction, $this->names($oldConflicts));
            }
            // Фоновый обработчик подставляет системное «Не распределено» при пустой статье.
            if ('cashflowCategory' === $field && !$this->hasCategory($oldCategory) && !$this->hasCategory($newCategory)) {
                continue;
            }
            if (($beforePlan->changes[$field]['after'] ?? null) !== ($afterPlan->changes[$field]['after'] ?? null)) {
                $this->record('value.'.$field, $transaction,
                    $this->valueLabel($field, $transaction, $beforePlan, $centers).' → '.$this->valueLabel($field, $transaction, $afterPlan, $centers));
            }
        }
    }

    private function hasCategory(?CashflowCategory $category): bool
    {
        return null !== $category && !in_array($category->getCode(), [CashflowCategory::CODE_UNALLOCATED, CashflowCategory::SYSTEM_UNALLOCATED], true);
    }

    /** @param list<CashTransactionAutoRule> $rules */
    private function names(array $rules): string
    {
        return implode(', ', array_map(static fn (CashTransactionAutoRule $rule): string => $rule->getName(), $rules));
    }

    /** @param array<string, string> $centers */
    private function valueLabel(string $field, CashTransaction $transaction, ?CashTransactionAutoRuleApplicationPlan $plan, array $centers): string
    {
        $changed = isset($plan->changes[$field]);
        $value = match ($field) {
            'cashflowCategory' => ($changed ? $plan->cashflowCategory : $transaction->getCashflowCategory())?->getName() ?? 'Не распределено',
            'counterparty' => ($changed ? $plan->counterparty : $transaction->getCounterparty())?->getName(),
            'projectDirection' => ($changed ? $plan->projectDirection : $transaction->getProjectDirection())?->getName(),
            'responsibilityCenterId' => $this->centerLabel($changed ? $plan->responsibilityCenterId : $transaction->getResponsibilityCenterId(), $centers),
            default => null,
        };

        return $value ?? '—';
    }

    /** @param array<string, string> $centers */
    private function centerLabel(?string $id, array $centers): ?string
    {
        return null !== $id ? ($centers[$id] ?? $id) : null;
    }
}
