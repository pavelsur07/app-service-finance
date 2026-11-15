<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Infrastructure\Query;

use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceWb\Domain\WbSourceTypeSeed;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Таблицы `mwb_*` через DBAL: решения по кодам, итоги переведённых дней, карантин.
 * Транзакцией владеет вызывающий (команда перевода).
 */
final readonly class WbTranslationStore
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Вид каждого кода: решение администратора, иначе справочник по умолчанию. Код без вида
     * при первой встрече записывается `pending` (на решение администратору).
     *
     * @param list<string> $sourceTypes
     *
     * @return array<string, OperationKind|null>
     */
    public function resolveKinds(array $sourceTypes, string $companyId, string $date): array
    {
        if ([] === $sourceTypes) {
            return [];
        }

        $decided = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT source_type, kind FROM mwb_source_type WHERE source_type IN (:types)',
            ['types' => $sourceTypes],
            ['types' => ArrayParameterType::STRING],
        ) as $row) {
            $decided[(string) $row['source_type']] = null === $row['kind'] ? null : OperationKind::from((string) $row['kind']);
        }

        $kinds = [];
        foreach ($sourceTypes as $sourceType) {
            if (\array_key_exists($sourceType, $decided)) {
                $kinds[$sourceType] = $decided[$sourceType];
                continue;
            }
            $kinds[$sourceType] = WbSourceTypeSeed::kind($sourceType);
            if (null === $kinds[$sourceType]) {
                $this->connection->executeStatement(
                    'INSERT INTO mwb_source_type (source_type, name, kind, status, first_seen_company_id, first_seen_date, first_seen_at)
                     VALUES (:type, :name, NULL, :status, :company, :date, :now) ON CONFLICT (source_type) DO NOTHING',
                    ['type' => $sourceType, 'name' => WbSourceTypeSeed::name($sourceType), 'status' => self::STATUS_PENDING, 'company' => $companyId, 'date' => $date, 'now' => $this->now()],
                );
            }
        }

        return $kinds;
    }

    /**
     * @param array<string, array{amountMinor: int, currency: string, rows: int, refs: list<string>}> $quarantine source_type => итог
     * @param array{revision: int, adsRevision: int, facts: int, totalMinor: int, canonMinor: int, quarantineMinor: int, currency: string, unresolved: int} $day
     */
    public function saveDay(string $companyId, string $connectionRef, string $date, string $rulesVersion, array $day, array $quarantine): void
    {
        $key = ['company' => $companyId, 'connection' => $connectionRef, 'date' => $date];
        $now = $this->now();

        $this->connection->executeStatement('DELETE FROM mwb_quarantine WHERE company_id = :company AND connection_ref = :connection AND operation_date = :date', $key);
        foreach ($quarantine as $sourceType => $row) {
            $this->connection->executeStatement(
                'INSERT INTO mwb_quarantine (company_id, connection_ref, operation_date, source_type, amount_minor, currency, row_count, source_refs, quarantined_at)
                 VALUES (:company, :connection, :date, :type, :amount, :currency, :rows, :refs, :now)',
                $key + [
                    'type' => $sourceType,
                    'amount' => $row['amountMinor'],
                    'currency' => $row['currency'],
                    'rows' => $row['rows'],
                    // ссылки — для разбора администратором, полный список не нужен
                    'refs' => json_encode(\array_slice($row['refs'], 0, 20), \JSON_THROW_ON_ERROR),
                    'now' => $now,
                ],
            );
        }

        $this->connection->executeStatement(
            'INSERT INTO mwb_day (company_id, connection_ref, operation_date, facts_revision, ads_revision, rules_version, fact_count, total_minor, canon_minor, quarantine_minor, currency, unresolved_listing_count, translated_at)
             VALUES (:company, :connection, :date, :revision, :ads, :rules, :facts, :total, :canon, :quarantine, :currency, :unresolved, :now)
             ON CONFLICT (company_id, connection_ref, operation_date) DO UPDATE SET
                facts_revision = EXCLUDED.facts_revision, ads_revision = EXCLUDED.ads_revision, rules_version = EXCLUDED.rules_version, fact_count = EXCLUDED.fact_count,
                total_minor = EXCLUDED.total_minor, canon_minor = EXCLUDED.canon_minor, quarantine_minor = EXCLUDED.quarantine_minor,
                currency = EXCLUDED.currency, unresolved_listing_count = EXCLUDED.unresolved_listing_count, translated_at = EXCLUDED.translated_at',
            $key + [
                'revision' => $day['revision'],
                'ads' => $day['adsRevision'],
                'rules' => $rulesVersion,
                'facts' => $day['facts'],
                'total' => $day['totalMinor'],
                'canon' => $day['canonMinor'],
                'quarantine' => $day['quarantineMinor'],
                'currency' => $day['currency'],
                'unresolved' => $day['unresolved'],
                'now' => $now,
            ],
        );
    }

    /**
     * Состояние переведённых дней: ключ `company|connection|date`.
     *
     * @companyScopeExempt переводчик сравнивает периоды всех компаний (cron)
     *
     * @return array<string, array{revision: int, ads: int, rules: string, unresolved: int}>
     */
    public function dayStates(): array
    {
        $states = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT company_id, connection_ref, operation_date, facts_revision, ads_revision, rules_version, unresolved_listing_count FROM mwb_day',
        ) as $row) {
            $states[self::dayKey((string) $row['company_id'], (string) $row['connection_ref'], (string) $row['operation_date'])] = [
                'revision' => (int) $row['facts_revision'],
                'ads' => (int) $row['ads_revision'],
                'rules' => (string) $row['rules_version'],
                'unresolved' => (int) $row['unresolved_listing_count'],
            ];
        }

        return $states;
    }

    /**
     * Σ, отправленная в канон, по переведённым дням окна — сверка «перевод = канон».
     *
     * @companyScopeExempt без `$companyId` — системный гейт по всем компаниям
     *
     * @return array<string, int> dayKey → canon_minor
     */
    public function canonTotals(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $companyId = null): array
    {
        $totals = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT company_id, connection_ref, operation_date, canon_minor FROM mwb_day WHERE operation_date BETWEEN :from AND :to'.(null === $companyId ? '' : ' AND company_id = :company'),
            ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')] + (null === $companyId ? [] : ['company' => $companyId]),
        ) as $row) {
            $totals[self::dayKey((string) $row['company_id'], (string) $row['connection_ref'], (string) $row['operation_date'])] = (int) $row['canon_minor'];
        }

        return $totals;
    }

    /**
     * Дни, в карантине которых есть код с уже принятым решением, — их надо перевести заново.
     *
     * @companyScopeExempt переводчик обходит все компании (cron)
     *
     * @return array<string, true>
     */
    public function daysWithDecidedQuarantine(): array
    {
        $days = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT DISTINCT q.company_id, q.connection_ref, q.operation_date FROM mwb_quarantine q
             JOIN mwb_source_type t ON t.source_type = q.source_type WHERE t.kind IS NOT NULL',
        ) as $row) {
            $days[self::dayKey((string) $row['company_id'], (string) $row['connection_ref'], (string) $row['operation_date'])] = true;
        }

        return $days;
    }

    /**
     * Для гейта: коды без решения, которые лежат в карантине дольше порога.
     *
     * @companyScopeExempt системный гейт по всем компаниям
     *
     * @return list<array{source_type: string, days: int, amount_minor: int}>
     */
    public function stalePending(\DateTimeImmutable $before): array
    {
        /** @var list<array{source_type: string, days: int, amount_minor: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT q.source_type, count(*)::int AS days, sum(q.amount_minor)::bigint AS amount_minor FROM mwb_quarantine q
             LEFT JOIN mwb_source_type t ON t.source_type = q.source_type
             WHERE t.kind IS NULL AND q.quarantined_at < :before
             GROUP BY q.source_type ORDER BY q.source_type',
            ['before' => $before->format('Y-m-d H:i:s')],
        );

        return $rows;
    }

    /**
     * Для экрана администратора: коды без решения с суммой и охватом карантина и черновиком ИИ.
     *
     * @companyScopeExempt справочник кодов общий для платформы
     *
     * @return list<array{source_type: string, name: string, ai_draft: array<string, mixed>|null, first_seen_at: string, days: int, companies: int, amount_minor: int}>
     */
    public function pendingForReview(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.source_type, t.name, t.ai_draft::text AS ai_draft, t.first_seen_at,
                    count(q.source_type)::int AS days, count(DISTINCT q.company_id)::int AS companies,
                    COALESCE(sum(q.amount_minor), 0)::bigint AS amount_minor
             FROM mwb_source_type t
             LEFT JOIN mwb_quarantine q ON q.source_type = t.source_type
             WHERE t.kind IS NULL
             GROUP BY t.source_type, t.name, t.ai_draft::text, t.first_seen_at
             ORDER BY t.first_seen_at',
        );

        return array_map(static function (array $r): array {
            $draft = null === $r['ai_draft'] ? null : json_decode((string) $r['ai_draft'], true, 512, \JSON_THROW_ON_ERROR);

            return [
                'source_type' => (string) $r['source_type'],
                'name' => (string) $r['name'],
                'ai_draft' => \is_array($draft) ? $draft : null,
                'first_seen_at' => (string) $r['first_seen_at'],
                'days' => (int) $r['days'],
                'companies' => (int) $r['companies'],
                'amount_minor' => (int) $r['amount_minor'],
            ];
        }, $rows);
    }

    /**
     * Коды без решения и без черновика ИИ — на запрос черновика. `debit` — знак суммы в карантине.
     *
     * @companyScopeExempt справочник кодов общий для платформы
     *
     * @return list<array{source_type: string, name: string, debit: bool}>
     */
    public function pendingWithoutDraft(): array
    {
        return array_map(static fn (array $r): array => [
            'source_type' => (string) $r['source_type'],
            'name' => (string) $r['name'],
            'debit' => (bool) $r['debit'],
        ], $this->connection->fetchAllAssociative(
            'SELECT t.source_type, t.name,
                    COALESCE((SELECT sum(q.amount_minor) FROM mwb_quarantine q WHERE q.source_type = t.source_type), 0) < 0 AS debit
             FROM mwb_source_type t
             WHERE t.kind IS NULL AND (t.ai_draft IS NULL OR (t.ai_draft->>\'status\' = \'requested\' AND (t.ai_draft->>\'requested_at\')::timestamptz < :stale))
             ORDER BY t.first_seen_at',
            ['stale' => $this->staleDraftBefore()],
        ));
    }

    /**
     * Черновик ИИ: состояние запроса или ответ. `null` — запросить снова в следующий прогон.
     *
     * @param array<string, mixed>|null $draft
     */
    public function saveDraft(string $sourceType, ?array $draft): void
    {
        $this->connection->executeStatement(
            'UPDATE mwb_source_type SET ai_draft = :draft WHERE source_type = :type AND kind IS NULL',
            ['type' => $sourceType, 'draft' => null === $draft ? null : json_encode($draft, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)],
        );
    }

    /**
     * @companyScopeExempt справочник кодов общий для платформы
     */
    public function sourceTypeByDraftTask(string $taskId): ?string
    {
        $type = $this->connection->fetchOne(
            "SELECT source_type FROM mwb_source_type WHERE kind IS NULL AND ai_draft->>'task_id' = :task",
            ['task' => $taskId],
        );

        return false === $type ? null : (string) $type;
    }

    /**
     * Решённые коды для примеров ИИ: решения администратора поверх справочника по умолчанию.
     *
     * @companyScopeExempt справочник кодов общий для платформы
     *
     * @return array<string, array{name: string, kind: OperationKind}>
     */
    public function decidedSourceTypes(): array
    {
        $decided = [];
        foreach (WbSourceTypeSeed::TYPES as $sourceType => [$name, $kind]) {
            if (null !== $kind) {
                $decided[$sourceType] = ['name' => $name, 'kind' => $kind];
            }
        }
        foreach ($this->connection->fetchAllAssociative('SELECT source_type, name, kind FROM mwb_source_type WHERE kind IS NOT NULL') as $row) {
            $decided[(string) $row['source_type']] = ['name' => (string) $row['name'], 'kind' => OperationKind::from((string) $row['kind'])];
        }

        return $decided;
    }

    /**
     * Решение администратора. Только для кода без решения: повторная отправка формы ничего не меняет.
     *
     * @return bool true — решение записано
     */
    public function confirm(string $sourceType, OperationKind $kind, string $name, string $userId): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE mwb_source_type SET kind = :kind, name = :name, status = :status, decided_by = :user, decided_at = :now
             WHERE source_type = :type AND kind IS NULL',
            ['type' => $sourceType, 'kind' => $kind->value, 'name' => $name, 'status' => self::STATUS_CONFIRMED, 'user' => $userId, 'now' => $this->now()],
        );
    }

    public static function dayKey(string $companyId, string $connectionRef, string $date): string
    {
        return $companyId.'|'.$connectionRef.'|'.$date;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /** Запрос черновика без ответа дольше часа (постановка упала окончательно) — запросить снова: сервис сольёт дубль по ключу. */
    private function staleDraftBefore(): string
    {
        return $this->clock->now()->modify('-1 hour')->format(\DATE_ATOM);
    }
}
