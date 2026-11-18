<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use App\Cash\Application\DTO\CashRulePhraseReview;
use App\Cash\Application\DTO\CashRulePhraseTarget;
use App\Cash\Application\DTO\CashTransactionAutoRuleCandidate;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Util\StringNormalizer;
use Doctrine\DBAL\Connection;

final readonly class CashRulePhraseQuery
{
    private const SEARCHES = <<<'SQL'
        SELECT search.task_id, search.counterparty_id, party.name AS counterparty_name, search.direction,
               search.category_id, category.name AS category_name,
               CASE WHEN search.status IN ('pending', 'sending') AND search.requested_at < CURRENT_TIMESTAMP - INTERVAL '1 hour'
                    THEN 'delayed' ELSE search.status END AS status, search.phrase
        FROM cash_rule_phrase_search search
        JOIN counterparty party ON party.id = search.counterparty_id AND party.company_id = search.company_id AND NOT party.is_archived
        JOIN cashflow_categories category ON category.id = search.category_id AND category.company_id = search.company_id AND category.status = 'active'
        WHERE search.company_id = :company
        SQL;

    public function __construct(private Connection $db, private CashTransactionAutoRuleCandidateQuery $history)
    {
    }

    /** @return list<CashRulePhraseTarget> */
    public function searches(string $companyId): array
    {
        $rows = $this->db->fetchAllAssociative(self::SEARCHES.' ORDER BY requested_at DESC, task_id LIMIT 30', ['company' => $companyId]);

        return array_map(CashRulePhraseTarget::fromRow(...), $rows);
    }

    public function review(string $companyId, string $taskId): ?CashRulePhraseReview
    {
        $search = $this->db->fetchAssociative(self::SEARCHES.' AND task_id = :task', ['company' => $companyId, 'task' => $taskId]);
        if (false === $search || 'ready' !== $search['status'] || !\is_string($search['phrase'])) {
            return null;
        }
        $counts = ['target' => 0, 'other' => 0];
        $examples = ['target' => [], 'other' => []];
        foreach ($this->history->phraseHistory($companyId, $search['counterparty_id'], $search['direction']) as $operation) {
            if (!StringNormalizer::contains($operation->description, $search['phrase'])) {
                continue;
            }
            $group = $operation->categoryId === $search['category_id'] ? 'target' : 'other';
            ++$counts[$group];
            if (\count($examples[$group]) < 10) {
                $examples[$group][] = $operation;
            }
        }
        $candidate = $counts['target'] < 2 ? null : new CashTransactionAutoRuleCandidate(
            CashTransactionAutoRuleConditionField::COUNTERPARTY, $search['counterparty_id'], $search['counterparty_name'],
            CashTransactionAutoRuleOperationType::from($search['direction']), $search['category_id'], $search['category_name'],
            $counts['target'], 0, phrase: $search['phrase'],
        );

        return new CashRulePhraseReview($candidate, $counts['target'], $counts['other'], $examples);
    }
}
