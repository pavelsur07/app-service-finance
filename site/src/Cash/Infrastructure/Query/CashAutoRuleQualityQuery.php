<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use App\Cash\Application\DTO\CashAutoRuleQualityCounts;
use App\Cash\Application\DTO\CashAutoRuleQualityPeriod;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashAutoRuleQualityMetric;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Pagerfanta\Adapter\CallbackAdapter;
use Pagerfanta\Pagerfanta;

final readonly class CashAutoRuleQualityQuery
{
    /**
     * Когорта — назначения по времени применения. Исправление относится к назначению, даже если
     * сделано позже конца периода. Лишь ближайшее изменение статьи может исправить это назначение.
     * У старых назначений с одинаковым временем порядок смен статей восстановить нельзя.
     * Такое соседство прерывает атрибуцию. Новые события записываются с микросекундами.
     */
    private const FACTS = <<<'SQL'
        WITH assignments AS (
            SELECT a.id, a.entity_id::uuid AS transaction_id, a.created_at,
                   a.diff #>> '{autoRules,cashflowCategory,id}' AS rule_id,
                   (a.diff #>> '{autoRules,cashflowCategory,revision}')::int AS revision
            FROM audit_log a
            WHERE a.company_id = :company AND a.entity_class = :class
              AND a.created_at >= :start AND a.created_at < :end
              AND a.diff #>> '{autoRules,cashflowCategory,id}' IN (:rules)
              AND a.diff #>> '{autoRules,cashflowCategory,revision}' IS NOT NULL
              AND a.diff #>> '{changes,cashflowCategory,after}' IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM cashflow_categories placeholder
                  WHERE placeholder.company_id = a.company_id
                    AND placeholder.system_code IN (:unallocated, :legacyUnallocated)
                    AND placeholder.id::text = a.diff #>> '{changes,cashflowCategory,after}'
              )
        ), applications AS (
            SELECT a.rule_id, a.revision, a.transaction_id, bool_or(correction.manual) IS TRUE AS corrected
            FROM assignments a
            LEFT JOIN LATERAL (
                SELECT bool_and(first_change.manual) AS manual
                FROM (
                    SELECT h.created_at > a.created_at AND h.actor_user_id IS NOT NULL
                               AND h.diff #> '{autoRules,cashflowCategory}' IS NULL
                               AND h.diff #> '{aiSuggestion,cashflowCategory}' IS NULL AS manual
                    FROM audit_log h
                    WHERE h.entity_class = :class AND h.entity_id = a.transaction_id::text AND h.company_id = :company
                      AND h.id <> a.id AND h.created_at >= a.created_at
                      AND (h.diff #> '{changes,cashflowCategory}' IS NOT NULL OR h.diff -> 'cashflowCategory' IS NOT NULL
                           OR jsonb_path_query_array(h.diff::jsonb, '$.splits[0][*].category')
                              <> jsonb_path_query_array(h.diff::jsonb, '$.splits[1][*].category'))
                    ORDER BY h.created_at FETCH FIRST 1 ROW WITH TIES
                ) first_change
            ) correction ON TRUE
            GROUP BY a.rule_id, a.revision, a.transaction_id
        ), observations AS (
            SELECT rule_id, revision, transaction_id, TRUE AS assigned, corrected, FALSE AS conflicts FROM applications
            UNION ALL
            SELECT rule_id::text, revision, transaction_id, FALSE, FALSE, TRUE
            FROM cash_auto_rule_category_conflict
            WHERE company_id = :company AND rule_id IN (:rules) AND observed_at >= :start AND observed_at < :end
        ), facts AS (
            SELECT rule_id, revision, transaction_id, bool_or(assigned) AS assigned,
                   bool_or(corrected) AS corrected, bool_or(conflicts) AS conflicts
            FROM observations
            JOIN cash_transaction tx ON tx.id = transaction_id AND tx.company_id = :company
            GROUP BY rule_id, revision, transaction_id
        )
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<string> $ruleIds
     *
     * @return array<string, array<int, CashAutoRuleQualityCounts>> по правилу и убывающей версии
     */
    public function summarize(string $companyId, array $ruleIds, CashAutoRuleQualityPeriod $period): array
    {
        if ([] === $ruleIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(self::FACTS.<<<'SQL'

            SELECT rule_id, revision, count(*) FILTER (WHERE assigned) AS assigned,
                   count(*) FILTER (WHERE corrected) AS corrected, count(*) FILTER (WHERE conflicts) AS conflicts
            FROM facts
            GROUP BY rule_id, revision ORDER BY rule_id, revision DESC
            SQL,
            $this->parameters($companyId, $ruleIds, $period),
            ['rules' => ArrayParameterType::STRING],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['rule_id']][(int) $row['revision']] = new CashAutoRuleQualityCounts(
                (int) $row['assigned'], (int) $row['corrected'], (int) $row['conflicts'],
            );
        }

        return $result;
    }

    /** @return Pagerfanta<array{id: string, occurred_at: string, description: ?string, amount: string, currency: string}> */
    public function operations(string $companyId, string $ruleId, int $revision, CashAutoRuleQualityMetric $metric, CashAutoRuleQualityPeriod $period, int $page): Pagerfanta
    {
        $where = ' FROM facts WHERE revision = :revision AND '.$metric->value;
        $params = $this->parameters($companyId, [$ruleId], $period) + ['revision' => $revision];
        $types = ['rules' => ArrayParameterType::STRING];

        return Pagerfanta::createForCurrentPageWithMaxPerPage(new CallbackAdapter(
            fn (): int => max(0, (int) $this->connection->fetchOne(self::FACTS.' SELECT count(*)'.$where, $params, $types)),
            fn (int $offset, int $limit): array => $this->connection->fetchAllAssociative(
                self::FACTS.' SELECT tx.id, tx.occurred_at, tx.description, tx.amount, tx.currency FROM (SELECT transaction_id'.$where.') matches
                    JOIN cash_transaction tx ON tx.id = matches.transaction_id AND tx.company_id = :company
                    ORDER BY tx.occurred_at DESC, tx.id LIMIT :limit OFFSET :offset',
                $params + ['limit' => $limit, 'offset' => $offset],
                $types,
            ),
        ), $page, 50);
    }

    /**
     * Первый вызов обработчика может открыть наблюдение между двумя чтениями.
     *
     * @phpstan-impure
     */
    public function observedSince(string $companyId): ?\DateTimeImmutable
    {
        $at = $this->connection->fetchOne('SELECT started_at FROM cash_auto_rule_observation WHERE company_id = ?', [$companyId]);

        return is_string($at) ? new \DateTimeImmutable($at) : null;
    }

    /**
     * @param list<string> $rules
     *
     * @return array{company: string, rules: list<string>, class: string, start: string, end: string, unallocated: string, legacyUnallocated: string}
     */
    private function parameters(string $companyId, array $rules, CashAutoRuleQualityPeriod $period): array
    {
        return [
            'company' => $companyId,
            'rules' => $rules,
            'class' => CashTransaction::class,
            'start' => $period->from->format('Y-m-d'),
            'end' => $period->to->modify('+1 day')->format('Y-m-d'),
            'unallocated' => CashflowCategory::CODE_UNALLOCATED,
            'legacyUnallocated' => CashflowCategory::SYSTEM_UNALLOCATED,
        ];
    }
}
