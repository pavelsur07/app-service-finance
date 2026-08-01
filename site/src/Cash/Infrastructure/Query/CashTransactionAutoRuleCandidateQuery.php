<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

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

        $sql = <<<'SQL'
            WITH confirmed_transactions AS (
                SELECT
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
                    SELECT (min(s.cashflow_category_id::text))::uuid AS cashflow_category_id
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
                        )
                        AND NOT jsonb_exists(latest_audit.diff::jsonb, 'autoRules')
                    ) AS manually_confirmed
                    FROM (
                        SELECT audit.actor_user_id, audit.diff
                        FROM audit_log audit
                        WHERE audit.company_id = :companyId
                          AND audit.entity_class = :entityClass
                          AND audit.entity_id = tx.id::text
                          AND (
                              jsonb_exists(audit.diff::jsonb, 'cashflowCategory')
                              OR audit.diff::jsonb #> '{changes,cashflowCategory}' IS NOT NULL
                          )
                        ORDER BY audit.created_at DESC
                        FETCH FIRST 1 ROW WITH TIES
                    ) latest_audit
                ) latest_category_audit ON latest_category_audit.manually_confirmed = true
                WHERE tx.company_id = :companyId
                  AND tx.deleted_at IS NULL
                  AND tx.occurred_at >= :from
                  AND tx.occurred_at <= :to
                  AND money_account.is_active = true
            ), samples AS (
                SELECT
                    confirmed.direction,
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
            ), candidates AS (
                SELECT
                    samples.field,
                    samples.value,
                    MIN(samples.value_label) AS value_label,
                    samples.direction,
                    MIN(samples.cashflow_category_id::text) AS category_id,
                    COUNT(*) AS sample_count,
                    COUNT(DISTINCT samples.occurred_at) AS distinct_date_count
                FROM samples
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
                candidates.sample_count,
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
                'entityClass' => CashTransaction::class,
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
            ),
            $rows,
        );
    }
}
