<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use App\Cash\Application\DTO\CashRulePhraseExample;
use App\Cash\Application\DTO\CashRulePhraseTarget;
use App\Cash\Application\DTO\CashTransactionAutoRuleCandidate;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Webmozart\Assert\Assert;

final readonly class CashTransactionAutoRuleCandidateQuery
{
    public const PERIOD_DAYS = 180;
    public const MIN_SAMPLES = 5;
    public const MIN_DISTINCT_DATES = 3;
    public const MAX_CANDIDATES = 100;

    private const CONFIRMED = <<<'SQL'
            WITH confirmed_transactions AS (
                SELECT
                    tx.id, tx.description, category.name AS category_name,
                    tx.direction,
                    tx.occurred_at,
                    split.cashflow_category_id,
                    tx.counterparty_id,
                    tx.money_account_id,
                    tx.currency,
                    tx.import_source,
                    tx.doc_type,
                    tx.is_transfer,
                    counterparty.name AS counterparty_name,
                    counterparty.is_archived AS counterparty_is_archived,
                    money_account.name AS money_account_name
                FROM cash_transaction tx
                -- Кандидат в правило обязан иметь ровно одну категорию: правило проставляет
                -- одну, и транзакция, разнесённая по нескольким статьям, чистой выборкой
                -- быть не может. HAVING отсекает мультиразбивку, INNER — транзакции без строк.
                INNER JOIN LATERAL (
                    SELECT (min(s.cashflow_category_id::text))::uuid AS cashflow_category_id, bool_and(s.source = 'manual') AS is_manual
                    FROM cash_transaction_split s
                    WHERE s.cash_transaction_id = tx.id
                    HAVING count(*) = 1
                ) split ON true
                INNER JOIN money_account
                    ON money_account.id = tx.money_account_id
                    AND money_account.company_id = :companyId
                INNER JOIN cashflow_categories category
                    ON category.id = split.cashflow_category_id
                    AND category.company_id = :companyId
                    AND category.status = 'active'
                    AND category.system_code IS DISTINCT FROM 'CF_UNALLOC'
                LEFT JOIN counterparty
                    ON counterparty.id = tx.counterparty_id
                    AND counterparty.company_id = :companyId
                INNER JOIN LATERAL (
                    SELECT BOOL_AND(
                        latest_audit.actor_user_id IS NOT NULL
                        AND (
                            jsonb_exists(latest_audit.diff::jsonb, 'cashflowCategory')
                            OR latest_audit.diff::jsonb #> '{changes,cashflowCategory}' IS NOT NULL
                            OR latest_audit.action = 'CREATE'
                            OR (latest_audit.diff::jsonb #>> '{splits,1,0,category}' = split.cashflow_category_id::text
                                AND latest_audit.diff::jsonb #>> '{splits,1,0,source}' = 'manual')
                        ) IS TRUE
                        AND NOT jsonb_exists(COALESCE(latest_audit.diff::jsonb, '{}'::jsonb), 'autoRules')
                    ) AS manually_confirmed
                    FROM (
                        SELECT audit.actor_user_id, audit.diff, audit.action
                        FROM audit_log audit
                        WHERE audit.company_id = :companyId
                          AND audit.entity_class = :entityClass
                          AND audit.entity_id = tx.id::text
                          AND (
                              jsonb_exists(audit.diff::jsonb, 'cashflowCategory')
                              OR audit.diff::jsonb #> '{changes,cashflowCategory}' IS NOT NULL
                              OR (CAST(:includeSplitEvents AS boolean) AND (
                                  audit.action = 'CREATE'
                                  OR (jsonb_exists(audit.diff::jsonb, 'splits') AND (
                                      jsonb_path_query_array(audit.diff::jsonb, '$.splits[0][*].category')
                                        <> jsonb_path_query_array(audit.diff::jsonb, '$.splits[1][*].category')
                                      OR jsonb_path_query_array(audit.diff::jsonb, '$.splits[0][*].source')
                                        <> jsonb_path_query_array(audit.diff::jsonb, '$.splits[1][*].source')
                                  ))
                              ))
                          )
                        -- CREATE не содержит diff и служит подтверждением только до первой смены статьи.
                        ORDER BY (CAST(:includeSplitEvents AS boolean) AND audit.action = 'CREATE'), audit.created_at DESC
                        FETCH FIRST 1 ROW WITH TIES
                    ) latest_audit
                ) latest_category_audit ON latest_category_audit.manually_confirmed = true
                WHERE tx.company_id = :companyId
                  AND (NOT CAST(:includeSplitEvents AS boolean) OR split.is_manual)
                  AND tx.deleted_at IS NULL
                  AND tx.occurred_at >= :from
                  AND tx.occurred_at <= :to
                  AND money_account.is_active = true
            )
            SQL;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<CashTransactionAutoRuleCandidate>
     */
    public function findForCompany(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        Assert::uuid($companyId);

        $sql = self::CONFIRMED.<<<'SQL'
            , samples AS (
                SELECT
                    confirmed.id, confirmed.direction,
                    confirmed.occurred_at,
                    confirmed.cashflow_category_id,
                    signal.field,
                    signal.value,
                    signal.value_label
                FROM confirmed_transactions confirmed
                CROSS JOIN LATERAL (VALUES
                    (
                        'COUNTERPARTY',
                        CASE WHEN confirmed.counterparty_is_archived = false THEN confirmed.counterparty_id::text END,
                        CASE WHEN confirmed.counterparty_is_archived = false THEN confirmed.counterparty_name END
                    ),
                    ('MONEY_ACCOUNT', confirmed.money_account_id::text, confirmed.money_account_name),
                    (
                        'IMPORT_SOURCE',
                        CASE
                            WHEN confirmed.import_source IS NULL THEN '__MISSING__'
                            WHEN NULLIF(TRIM(confirmed.import_source), '') IS NULL THEN NULL
                            ELSE confirmed.import_source
                        END,
                        CASE
                            WHEN confirmed.import_source IS NULL THEN 'Источник не указан'
                            WHEN NULLIF(TRIM(confirmed.import_source), '') IS NULL THEN NULL
                            ELSE confirmed.import_source
                        END
                    ),
                    ('CURRENCY', confirmed.currency, confirmed.currency),
                    ('DOCUMENT_TYPE', LOWER(TRIM(confirmed.doc_type)), TRIM(confirmed.doc_type)),
                    (
                        'IS_TRANSFER',
                        CASE WHEN confirmed.is_transfer THEN 'true' ELSE 'false' END,
                        CASE WHEN confirmed.is_transfer THEN 'Да' ELSE 'Нет' END
                    )
                ) AS signal(field, value, value_label)
                WHERE signal.value IS NOT NULL AND signal.value <> ''
            ), ranked_samples AS (
                SELECT id, occurred_at, field, value, value_label, direction, cashflow_category_id,
                    row_number() OVER (PARTITION BY field, value, direction ORDER BY occurred_at DESC, id) AS example_rank
                FROM samples
            ), candidates AS (
                SELECT
                    samples.field,
                    samples.value,
                    MIN(samples.value_label) AS value_label,
                    samples.direction,
                    MIN(samples.cashflow_category_id::text) AS category_id,
                    json_agg(json_build_object('id', samples.id, 'date', samples.occurred_at) ORDER BY samples.example_rank)
                        FILTER (WHERE samples.example_rank <= 5) AS examples,
                    COUNT(*) AS sample_count,
                    COUNT(DISTINCT samples.occurred_at) AS distinct_date_count
                FROM ranked_samples samples
                GROUP BY samples.field, samples.value, samples.direction
                HAVING COUNT(*) >= :minSamples
                   AND COUNT(DISTINCT samples.occurred_at) >= :minDistinctDates
                   AND COUNT(DISTINCT samples.cashflow_category_id) = 1
            )
            SELECT
                candidates.field,
                candidates.value,
                candidates.value_label,
                candidates.direction,
                candidates.category_id,
                category.name AS category_name,
                candidates.examples, candidates.sample_count,
                candidates.distinct_date_count
            FROM candidates
            INNER JOIN cashflow_categories category
                ON category.id = candidates.category_id::uuid
                AND category.company_id = :companyId
                AND category.status = 'active'
            ORDER BY candidates.sample_count DESC, candidates.field, candidates.value, candidates.direction
            LIMIT :maxCandidates
            SQL;

        $rows = $this->connection->executeQuery(
            $sql,
            [
                'companyId' => $companyId,
                'entityClass' => CashTransaction::class, 'includeSplitEvents' => 'false',
                'from' => $from,
                'to' => $to,
                'minSamples' => self::MIN_SAMPLES,
                'minDistinctDates' => self::MIN_DISTINCT_DATES,
                'maxCandidates' => self::MAX_CANDIDATES,
            ],
            [
                'from' => Types::DATE_IMMUTABLE,
                'to' => Types::DATE_IMMUTABLE,
                'minSamples' => Types::INTEGER,
                'minDistinctDates' => Types::INTEGER,
                'maxCandidates' => Types::INTEGER,
            ],
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row): CashTransactionAutoRuleCandidate => new CashTransactionAutoRuleCandidate(
                field: CashTransactionAutoRuleConditionField::from((string) $row['field']),
                value: (string) $row['value'],
                valueLabel: (string) $row['value_label'],
                operationType: CashTransactionAutoRuleOperationType::from((string) $row['direction']),
                categoryId: (string) $row['category_id'],
                categoryName: (string) $row['category_name'],
                sampleCount: (int) $row['sample_count'],
                distinctDateCount: (int) $row['distinct_date_count'],
                examples: json_decode((string) $row['examples'], true, flags: \JSON_THROW_ON_ERROR),
            ),
            $rows,
        );
    }

    /** @return list<CashRulePhraseTarget> */
    public function phraseTargets(string $companyId, int $lookbackDays): array
    {
        $rows = $this->connection->fetchAllAssociative(self::CONFIRMED.<<<'SQL'
            SELECT counterparty_id, counterparty_name, direction, cashflow_category_id AS category_id, category_name
            FROM confirmed_transactions
            WHERE counterparty_is_archived = false AND nullif(trim(description), '') IS NOT NULL
            GROUP BY counterparty_id, counterparty_name, direction, cashflow_category_id, category_name
            HAVING count(*) >= 2
            ORDER BY count(*) DESC, counterparty_name, category_name, direction, counterparty_id, cashflow_category_id
            LIMIT 100
            SQL, $this->periodParameters($companyId, $lookbackDays));

        return array_map(CashRulePhraseTarget::fromRow(...), $rows);
    }

    /**
     * $sampleForCategory ограничивает выгрузку в ИИ 30 последними платежами в каждой группе.
     * Локальная проверка фразы охватывает всю подтверждённую историю за период.
     *
     * @return iterable<CashRulePhraseExample>
     */
    public function phraseHistory(
        string $companyId,
        string $counterpartyId,
        string $direction,
        int $lookbackDays = self::PERIOD_DAYS,
        ?string $sampleForCategory = null,
    ): iterable {
        $sql = self::CONFIRMED.<<<'SQL'
            , phrase_history AS NOT MATERIALIZED (
                SELECT id, occurred_at, description, cashflow_category_id AS category_id, category_name
                FROM confirmed_transactions
                WHERE counterparty_id = :counterparty AND counterparty_is_archived = false
                  AND direction = :direction AND nullif(trim(description), '') IS NOT NULL
            )
            SQL;
        $parameters = $this->periodParameters($companyId, $lookbackDays) + ['counterparty' => $counterpartyId, 'direction' => $direction];
        $select = ' SELECT id, occurred_at, description, category_id, category_name FROM phrase_history';
        if (null === $sampleForCategory) {
            $sql .= $select;
        } else {
            $parameters['target'] = $sampleForCategory;
            $sql .= ' ('.$select.' WHERE category_id = :target ORDER BY occurred_at DESC, id LIMIT 30)'
                .' UNION ALL ('.$select.' WHERE category_id <> :target ORDER BY occurred_at DESC, id LIMIT 30)';
        }
        $rows = $this->connection->iterateAssociative($sql.' ORDER BY occurred_at DESC, id', $parameters);
        foreach ($rows as $row) {
            yield new CashRulePhraseExample((string) $row['id'], (string) $row['occurred_at'], (string) $row['description'], (string) $row['category_id'], (string) $row['category_name']);
        }
    }

    /** @return array<string, string> */
    private function periodParameters(string $companyId, int $lookbackDays): array
    {
        $today = new \DateTimeImmutable('today');

        return [
            'companyId' => $companyId, 'entityClass' => CashTransaction::class, 'includeSplitEvents' => 'true',
            'from' => $today->modify('-'.min($lookbackDays, self::PERIOD_DAYS).' days')->format('Y-m-d'), 'to' => $today->format('Y-m-d'),
        ];
    }
}
