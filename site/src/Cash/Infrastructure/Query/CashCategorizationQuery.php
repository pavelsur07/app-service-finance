<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use App\Cash\Application\DTO\CashAiAutoApplyGateStats;
use App\Cash\Application\DTO\CashAiRuleProposal;
use App\Cash\Application\DTO\CashCategorizationCategory;
use App\Cash\Application\DTO\CashCategorizationHistoryCase;
use App\Cash\Application\DTO\CashCategorizationOperation;
use App\Cash\Application\DTO\CashCategorizationStats;
use App\Cash\Application\DTO\CashCategorySuggestionView;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashflowCategoryStatus;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

/**
 * Данные для ИИ-подсказок статей ДДС (docs/tasks/ai/stages/stage-2.md).
 *
 * «Без статьи» — статьи нет или это системная «Нераспределённое» (`CF_UNALLOC`,
 * легаси `UNALLOCATED`), не больше одной строки разбивки (мультиразбивку сделал человек),
 * не перевод, не удалена, дата не в закрытом периоде компании.
 */
final readonly class CashCategorizationQuery
{
    /** Операция с таким числом сбоев ответа больше не запрашивается: не платить за безнадёжные. Истечение без ответа (`expired`) — сбой доставки, не считается. */
    public const MAX_FAILED_ATTEMPTS = 3;

    private const UNCATEGORIZED = <<<'SQL'
        tx.company_id = :companyId
        AND tx.deleted_at IS NULL
        AND tx.is_transfer = false
        AND (tx.cashflow_category_id IS NULL OR category.system_code IN (:unallocatedCodes))
        AND (SELECT count(*) FROM cash_transaction_split split WHERE split.cash_transaction_id = tx.id) <= 1
        AND (company.finance_lock_before IS NULL OR tx.occurred_at >= company.finance_lock_before)
        SQL;

    /** Порог предложения автоправила по ИИ-подсказкам: операций со статьёй человека. */
    public const RULE_PROPOSAL_MIN_CONFIRMED = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Операции без статьи, которым подсказку ещё не запрашивали (или прошлые попытки сорвались).
     *
     * @return list<CashCategorizationOperation>
     */
    public function findCandidates(string $companyId, \DateTimeImmutable $occurredFrom, \DateTimeImmutable $createdBefore, int $limit): array
    {
        Assert::uuid($companyId);
        Assert::range($limit, 1, 1000);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT tx.id, tx.direction, tx.amount, tx.currency, tx.occurred_at, tx.description,
                    COALESCE(counterparty.display_name, counterparty.name) AS counterparty
             FROM cash_transaction tx
             JOIN "companies" company ON company.id = tx.company_id
             LEFT JOIN "cashflow_categories" category ON category.id = tx.cashflow_category_id
             LEFT JOIN counterparty ON counterparty.id = tx.counterparty_id AND counterparty.company_id = :companyId
             WHERE '.self::UNCATEGORIZED.'
               AND tx.occurred_at >= :occurredFrom
               AND tx.created_at < :createdBefore
               AND NOT EXISTS (
                   SELECT 1 FROM cash_category_suggestion s
                   WHERE s.company_id = :companyId AND s.transaction_id = tx.id
                     AND s.status NOT IN (\'failed\', \'stale\')
               )
               AND (
                   SELECT count(*) FROM cash_category_suggestion s
                   WHERE s.company_id = :companyId AND s.transaction_id = tx.id AND s.status = \'failed\' AND s.error_code IS DISTINCT FROM \'expired\'
               ) < :maxFailed
             ORDER BY tx.occurred_at DESC, tx.id
             LIMIT :limit',
            [
                'companyId' => $companyId,
                'unallocatedCodes' => [CashflowCategory::CODE_UNALLOCATED, CashflowCategory::SYSTEM_UNALLOCATED],
                'occurredFrom' => $occurredFrom->format('Y-m-d'),
                'createdBefore' => $createdBefore->format('Y-m-d H:i:s'),
                'maxFailed' => self::MAX_FAILED_ATTEMPTS,
                'limit' => $limit,
            ],
            ['unallocatedCodes' => ArrayParameterType::STRING],
        );

        return array_map(self::operation(...), $rows);
    }

    /**
     * История ручных категоризаций для золотого набора evals (docs/tasks/ai/stages/stage-2.md, 2.2):
     * операции с одной ручной разбивкой и несистемной статьёй, не переводы, не удалённые, без
     * принятой ИИ-подсказки (набор не учится на ответах модели). Не больше `$perCategory`
     * последних операций на статью — частые статьи не заслоняют редкие.
     *
     * @return list<CashCategorizationHistoryCase>
     */
    public function findManuallyCategorized(string $companyId, int $perCategory, int $limit): array
    {
        Assert::uuid($companyId);
        Assert::range($perCategory, 1, 1000);
        Assert::range($limit, 1, 10000);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ranked.id, ranked.direction, ranked.amount, ranked.currency, ranked.occurred_at,
                    ranked.description, ranked.counterparty, ranked.category_id
             FROM (
                 SELECT tx.id, tx.direction, tx.amount, tx.currency, tx.occurred_at, tx.description,
                        COALESCE(counterparty.display_name, counterparty.name) AS counterparty,
                        tx.cashflow_category_id AS category_id,
                        row_number() OVER (PARTITION BY tx.cashflow_category_id ORDER BY tx.occurred_at DESC, tx.id) AS rank
                 FROM cash_transaction tx
                 JOIN "cashflow_categories" category ON category.id = tx.cashflow_category_id AND category.company_id = :companyId
                 JOIN cash_transaction_split split ON split.cash_transaction_id = tx.id AND split.source = :manual
                 LEFT JOIN counterparty ON counterparty.id = tx.counterparty_id AND counterparty.company_id = :companyId
                 WHERE tx.company_id = :companyId
                   AND tx.deleted_at IS NULL
                   AND tx.is_transfer = false
                   AND category.is_system = false
                   AND (SELECT count(*) FROM cash_transaction_split other WHERE other.cash_transaction_id = tx.id) = 1
                   AND NOT EXISTS (
                       SELECT 1 FROM cash_category_suggestion s
                       WHERE s.company_id = :companyId AND s.transaction_id = tx.id AND s.status = \'accepted\'
                   )
             ) ranked
             WHERE ranked.rank <= :perCategory
             ORDER BY ranked.occurred_at DESC, ranked.id
             LIMIT :limit',
            [
                'companyId' => $companyId,
                'manual' => CashTransactionSplitSource::MANUAL->value,
                'perCategory' => $perCategory,
                'limit' => $limit,
            ],
        );

        return array_map(static fn (array $row): CashCategorizationHistoryCase => new CashCategorizationHistoryCase(
            self::operation($row),
            (string) $row['category_id'],
        ), $rows);
    }

    /**
     * Решения людей по подсказкам с уверенностью не ниже `$minConfidence` — ворота автоприменения.
     * Решение правкой операции — подсказка висела, а операция получила статью; исправленное
     * автоприменение — статья операции больше не та, что применил ИИ (удалённые не считаются).
     * Правка считается решением человека только при ручной разбивке (`manual`): статья от
     * автоправила или импорта качество подсказок не подтверждает и не опровергает.
     */
    public function autoApplyGateStats(string $companyId, float $minConfidence): CashAiAutoApplyGateStats
    {
        Assert::uuid($companyId);

        $row = $this->connection->fetchAssociative(
            'SELECT
                 count(*) FILTER (WHERE d.status = \'accepted\' OR (d.status = \'suggested\' AND d.edited AND d.tx_category = d.category_id)) AS right_count,
                 count(*) FILTER (WHERE d.status = \'dismissed\' OR (d.status = \'suggested\' AND d.edited AND d.tx_category <> d.category_id)) AS wrong_count,
                 count(*) FILTER (WHERE d.status = \'auto_applied\') AS auto_applied,
                 count(*) FILTER (WHERE d.status = \'auto_applied\' AND d.edited AND d.tx_category <> d.category_id) AS auto_corrected
             FROM (
                 SELECT s.status, s.category_id, tx.cashflow_category_id AS tx_category,
                        (tx.cashflow_category_id IS NOT NULL
                         AND category.system_code IS DISTINCT FROM :unallocated
                         AND category.system_code IS DISTINCT FROM :legacyUnallocated
                         AND EXISTS (
                             SELECT 1 FROM cash_transaction_split split
                             WHERE split.cash_transaction_id = tx.id AND split.source = :manual
                         )) AS edited
                 FROM cash_category_suggestion s
                 JOIN cash_transaction tx ON tx.id = s.transaction_id AND tx.company_id = :companyId
                 LEFT JOIN "cashflow_categories" category ON category.id = tx.cashflow_category_id
                 WHERE s.company_id = :companyId AND s.confidence >= :minConfidence AND tx.deleted_at IS NULL
             ) d',
            [
                'companyId' => $companyId,
                'minConfidence' => $minConfidence,
                'unallocated' => CashflowCategory::CODE_UNALLOCATED,
                'legacyUnallocated' => CashflowCategory::SYSTEM_UNALLOCATED,
                'manual' => CashTransactionSplitSource::MANUAL->value,
            ],
        );
        $autoCorrected = (int) ($row['auto_corrected'] ?? 0);

        return new CashAiAutoApplyGateStats(
            right: (int) ($row['right_count'] ?? 0),
            // Исправленное автоприменение — ошибка, которую человек увидел и поправил.
            wrong: (int) ($row['wrong_count'] ?? 0) + $autoCorrected,
            autoApplied: (int) ($row['auto_applied'] ?? 0),
            autoCorrected: $autoCorrected,
        );
    }

    /**
     * Предложения автоправил «контрагент + направление → статья» по операциям с ИИ-подсказкой.
     *
     * Статья человека — текущая статья операции с ручной разбивкой (источник разбивки
     * меняется только вместе со статьёй); автоприменение без человека — не решение.
     * Подтверждение — статья человека совпала с подсказкой; против — подсказка отклонена или
     * человек выбрал другую статью. Предложение — не меньше `$minConfirmed` подтверждённых
     * операций, все с одной статьёй, и ни одного решения против в группе. Контрагент в
     * архиве или уже с активным правилом по контрагенту — не предлагается.
     *
     * @return list<CashAiRuleProposal>
     */
    public function findRuleProposals(string $companyId, int $minConfirmed = self::RULE_PROPOSAL_MIN_CONFIRMED, int $limit = 50): array
    {
        Assert::uuid($companyId);
        Assert::range($minConfirmed, 1, 1000);
        Assert::range($limit, 1, 500);

        $rows = $this->connection->fetchAllAssociative(
            'WITH d AS (
                 SELECT s.transaction_id, s.status, s.category_id, tx.counterparty_id, tx.direction, tx.occurred_at,
                        CASE WHEN s.status IN (\'accepted\', \'suggested\', \'auto_applied\')
                              AND tx.cashflow_category_id IS NOT NULL
                              AND category.system_code IS DISTINCT FROM :unallocated
                              AND category.system_code IS DISTINCT FROM :legacyUnallocated
                              AND EXISTS (
                                  SELECT 1 FROM cash_transaction_split split
                                  WHERE split.cash_transaction_id = tx.id AND split.source = :manual
                              )
                             THEN tx.cashflow_category_id END AS human_category,
                        (s.status = \'suggested\' AND '.self::UNCATEGORIZED.') AS pending
                 FROM cash_category_suggestion s
                 JOIN cash_transaction tx ON tx.id = s.transaction_id AND tx.company_id = :companyId
                 JOIN "companies" company ON company.id = tx.company_id
                 LEFT JOIN "cashflow_categories" category ON category.id = tx.cashflow_category_id
                 WHERE s.company_id = :companyId AND tx.deleted_at IS NULL AND tx.counterparty_id IS NOT NULL
             ), v AS (
                 SELECT d.*,
                        (d.human_category = d.category_id) AS confirmed,
                        (d.status = \'dismissed\' OR d.human_category <> d.category_id) AS against
                 FROM d
             ), g AS (
                 SELECT v.counterparty_id, v.direction,
                        min(v.category_id::text) FILTER (WHERE v.confirmed)::uuid AS category_id,
                        count(DISTINCT v.transaction_id) FILTER (WHERE v.confirmed) AS confirmed,
                        (array_agg(v.transaction_id ORDER BY v.occurred_at DESC, v.transaction_id DESC)
                            FILTER (WHERE v.confirmed))[1] AS sample_transaction_id
                 FROM v
                 GROUP BY v.counterparty_id, v.direction
                 HAVING count(DISTINCT v.transaction_id) FILTER (WHERE v.confirmed) >= :minConfirmed
                    AND count(DISTINCT v.category_id) FILTER (WHERE v.confirmed) = 1
                    AND NOT coalesce(bool_or(v.against), false)
             )
             SELECT g.counterparty_id, counterparty.name AS counterparty_name, g.direction, g.category_id,
                    category.name AS category_name, g.confirmed, g.sample_transaction_id,
                    (SELECT count(DISTINCT p.transaction_id) FROM d p
                     WHERE p.counterparty_id = g.counterparty_id AND p.direction = g.direction
                       AND p.pending AND p.category_id = g.category_id) AS pending
             FROM g
             JOIN "counterparty" counterparty ON counterparty.id = g.counterparty_id
                  AND counterparty.company_id = :companyId AND counterparty.is_archived = false
             JOIN "cashflow_categories" category ON category.id = g.category_id
                  AND category.company_id = :companyId AND category.status = :active
             WHERE NOT EXISTS (
                       SELECT 1
                       FROM cash_transaction_auto_rule rule
                       JOIN cash_transaction_auto_rule_condition condition ON condition.auto_rule_id = rule.id
                       WHERE rule.company_id = :companyId AND rule.is_active
                         AND condition.field = :counterpartyField AND condition.counterparty_id = g.counterparty_id
                   )
             ORDER BY g.confirmed DESC, counterparty.name, g.direction
             LIMIT :limit',
            [
                'companyId' => $companyId,
                'unallocated' => CashflowCategory::CODE_UNALLOCATED,
                'legacyUnallocated' => CashflowCategory::SYSTEM_UNALLOCATED,
                'unallocatedCodes' => [CashflowCategory::CODE_UNALLOCATED, CashflowCategory::SYSTEM_UNALLOCATED],
                'manual' => CashTransactionSplitSource::MANUAL->value,
                'active' => CashflowCategoryStatus::ACTIVE->value,
                'counterpartyField' => CashTransactionAutoRuleConditionField::COUNTERPARTY->value,
                'minConfirmed' => $minConfirmed,
                'limit' => $limit,
            ],
            ['unallocatedCodes' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): CashAiRuleProposal => new CashAiRuleProposal(
            counterpartyId: (string) $row['counterparty_id'],
            counterpartyName: (string) $row['counterparty_name'],
            direction: CashDirection::from((string) $row['direction']),
            categoryId: (string) $row['category_id'],
            categoryName: (string) $row['category_name'],
            confirmed: (int) $row['confirmed'],
            pending: (int) $row['pending'],
            sampleTransactionId: (string) $row['sample_transaction_id'],
        ), $rows);
    }

    /**
     * Подсказки, которые можно применить без человека: показаны, уверенность не ниже порога,
     * вердикт сервиса `accepted`, операция всё ещё без статьи.
     *
     * @return list<string> id подсказок
     */
    public function findAutoApplicable(string $companyId, float $minConfidence, int $limit): array
    {
        Assert::uuid($companyId);
        Assert::range($limit, 1, 1000);

        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT s.id
             FROM cash_category_suggestion s
             JOIN cash_transaction tx ON tx.id = s.transaction_id
             JOIN "companies" company ON company.id = tx.company_id
             LEFT JOIN "cashflow_categories" category ON category.id = tx.cashflow_category_id
             WHERE s.company_id = :companyId AND s.status = \'suggested\' AND s.verdict = \'accepted\'
               AND s.confidence >= :minConfidence
               AND '.self::UNCATEGORIZED.'
             ORDER BY s.requested_at, s.id
             LIMIT :limit',
            [
                'companyId' => $companyId,
                'unallocatedCodes' => [CashflowCategory::CODE_UNALLOCATED, CashflowCategory::SYSTEM_UNALLOCATED],
                'minConfidence' => $minConfidence,
                'limit' => $limit,
            ],
            ['unallocatedCodes' => ArrayParameterType::STRING],
        ));
    }

    /**
     * Автоприменённые статьи, которые всё ещё стоят на операции: значок «ИИ» в списке.
     *
     * @param list<string> $transactionIds
     *
     * @return array<string, int> id операции → уверенность, %
     */
    public function autoAppliedConfidence(string $companyId, array $transactionIds): array
    {
        Assert::uuid($companyId);
        Assert::allUuid($transactionIds);
        if ([] === $transactionIds) {
            return [];
        }

        $rows = $this->connection->fetchAllKeyValue(
            'SELECT s.transaction_id, s.confidence
             FROM cash_category_suggestion s
             JOIN cash_transaction tx ON tx.id = s.transaction_id AND tx.company_id = :companyId
             WHERE s.company_id = :companyId AND s.status = \'auto_applied\' AND s.transaction_id IN (:ids)
               AND tx.cashflow_category_id = s.category_id',
            ['companyId' => $companyId, 'ids' => $transactionIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(static fn (mixed $confidence): int => (int) round(100 * (float) $confidence), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function operation(array $row): CashCategorizationOperation
    {
        return new CashCategorizationOperation(
            id: (string) $row['id'],
            direction: (string) $row['direction'],
            amount: (string) $row['amount'],
            currency: (string) $row['currency'],
            occurredAt: new \DateTimeImmutable((string) $row['occurred_at']),
            description: null === $row['description'] ? null : (string) $row['description'],
            counterparty: null === $row['counterparty'] ? null : (string) $row['counterparty'],
        );
    }

    /**
     * Конечные активные несистемные статьи компании с путём от корня.
     *
     * @return list<CashCategorizationCategory>
     */
    public function findLeafCategories(string $companyId): array
    {
        Assert::uuid($companyId);

        $rows = $this->connection->fetchAllAssociative(
            'WITH RECURSIVE tree AS (
                 SELECT c.id, c.parent_id, c.name::text AS path, c.status, c.is_system, c.operation_type
                 FROM "cashflow_categories" c
                 WHERE c.company_id = :companyId AND c.parent_id IS NULL
                 UNION ALL
                 SELECT c.id, c.parent_id, tree.path || \' / \' || c.name, c.status, c.is_system, c.operation_type
                 FROM "cashflow_categories" c
                 JOIN tree ON tree.id = c.parent_id
                 WHERE c.company_id = :companyId
             )
             SELECT tree.id, tree.path, tree.operation_type
             FROM tree
             WHERE tree.status = \'active\'
               AND tree.is_system = false
               AND NOT EXISTS (SELECT 1 FROM "cashflow_categories" child WHERE child.parent_id = tree.id)
             ORDER BY tree.path',
            ['companyId' => $companyId],
        );

        return array_map(static fn (array $row): CashCategorizationCategory => new CashCategorizationCategory(
            id: (string) $row['id'],
            path: (string) $row['path'],
            operationType: null === $row['operation_type'] ? null : (string) $row['operation_type'],
        ), $rows);
    }

    /**
     * Какие из операций всё ещё без статьи: подсказка к остальным устарела.
     *
     * @param list<string> $transactionIds
     *
     * @return array<string, true>
     */
    public function uncategorizedAmong(string $companyId, array $transactionIds): array
    {
        Assert::uuid($companyId);
        Assert::allUuid($transactionIds);
        if ([] === $transactionIds) {
            return [];
        }

        $ids = $this->connection->fetchFirstColumn(
            'SELECT tx.id
             FROM cash_transaction tx
             JOIN "companies" company ON company.id = tx.company_id
             LEFT JOIN "cashflow_categories" category ON category.id = tx.cashflow_category_id
             WHERE '.self::UNCATEGORIZED.' AND tx.id IN (:ids)',
            [
                'companyId' => $companyId,
                'unallocatedCodes' => [CashflowCategory::CODE_UNALLOCATED, CashflowCategory::SYSTEM_UNALLOCATED],
                'ids' => $transactionIds,
            ],
            ['unallocatedCodes' => ArrayParameterType::STRING, 'ids' => ArrayParameterType::STRING],
        );

        return array_fill_keys(array_map('strval', $ids), true);
    }

    /**
     * Показываемые подсказки для операций страницы списка: одна выборка на страницу.
     * Операция, уже получившая статью, подсказку не показывает.
     *
     * @param list<string> $transactionIds
     *
     * @return array<string, CashCategorySuggestionView> transactionId → подсказка
     */
    public function shownSuggestions(string $companyId, array $transactionIds): array
    {
        Assert::uuid($companyId);
        Assert::allUuid($transactionIds);
        if ([] === $transactionIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.transaction_id, s.confidence, s.verdict, suggested.name AS category_name
             FROM cash_category_suggestion s
             JOIN cash_transaction tx ON tx.id = s.transaction_id
             JOIN "companies" company ON company.id = tx.company_id
             LEFT JOIN "cashflow_categories" category ON category.id = tx.cashflow_category_id
             JOIN "cashflow_categories" suggested ON suggested.id = s.category_id AND suggested.company_id = :companyId
             WHERE s.company_id = :companyId AND s.status = \'suggested\' AND s.transaction_id IN (:ids)
               AND '.self::UNCATEGORIZED,
            [
                'companyId' => $companyId,
                'unallocatedCodes' => [CashflowCategory::CODE_UNALLOCATED, CashflowCategory::SYSTEM_UNALLOCATED],
                'ids' => $transactionIds,
            ],
            ['unallocatedCodes' => ArrayParameterType::STRING, 'ids' => ArrayParameterType::STRING],
        );

        $views = [];
        foreach ($rows as $row) {
            $views[(string) $row['transaction_id']] = new CashCategorySuggestionView(
                id: (string) $row['id'],
                categoryName: (string) $row['category_name'],
                confidencePercent: (int) round(100 * (float) $row['confidence']),
                needsReview: 'accepted' !== $row['verdict'],
            );
        }

        return $views;
    }

    public function stats(string $companyId): CashCategorizationStats
    {
        Assert::uuid($companyId);

        /** @var array<string, int|string> $byStatus */
        $byStatus = $this->connection->fetchAllKeyValue(
            'SELECT status, count(*) FROM cash_category_suggestion WHERE company_id = :companyId GROUP BY status',
            ['companyId' => $companyId],
        );
        // Висящая подсказка у операции, которая уже со статьёй: человек решил правкой операции.
        $edited = $this->connection->fetchAssociative(
            'SELECT count(*) FILTER (WHERE tx.cashflow_category_id IS DISTINCT FROM s.category_id) AS corrected,
                    count(*) FILTER (WHERE tx.cashflow_category_id = s.category_id) AS confirmed
             FROM cash_category_suggestion s
             JOIN cash_transaction tx ON tx.id = s.transaction_id AND tx.company_id = :companyId
             LEFT JOIN "cashflow_categories" category ON category.id = tx.cashflow_category_id
             WHERE s.company_id = :companyId AND s.status = \'suggested\'
               AND tx.deleted_at IS NULL
               AND tx.cashflow_category_id IS NOT NULL
               AND category.system_code IS DISTINCT FROM :unallocated
               AND category.system_code IS DISTINCT FROM :legacyUnallocated',
            [
                'companyId' => $companyId,
                'unallocated' => CashflowCategory::CODE_UNALLOCATED,
                'legacyUnallocated' => CashflowCategory::SYSTEM_UNALLOCATED,
            ],
        );

        return new CashCategorizationStats(
            byStatus: array_map('intval', $byStatus),
            corrected: (int) ($edited['corrected'] ?? 0),
            confirmedByEdit: (int) ($edited['confirmed'] ?? 0),
        );
    }
}
