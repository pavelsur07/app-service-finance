<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Infrastructure\Query;

use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceOzon\Domain\OzonAccrualTypeSeed;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Таблицы `moz_*` через DBAL: решения по кодам, итоги переведённых дней, карантин.
 * Транзакцией владеет вызывающий (команда перевода).
 */
final readonly class OzonTranslationStore
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
            'SELECT source_type, kind FROM moz_source_type WHERE source_type IN (:types)',
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
            $kinds[$sourceType] = self::seedKind($sourceType);
            if (null === $kinds[$sourceType]) {
                $this->connection->executeStatement(
                    'INSERT INTO moz_source_type (source_type, name, kind, status, first_seen_company_id, first_seen_date, first_seen_at)
                     VALUES (:type, :name, NULL, :status, :company, :date, :now) ON CONFLICT (source_type) DO NOTHING',
                    ['type' => $sourceType, 'name' => self::seedName($sourceType), 'status' => self::STATUS_PENDING, 'company' => $companyId, 'date' => $date, 'now' => $this->now()],
                );
            }
        }

        return $kinds;
    }

    /**
     * @param array<string, array{amountMinor: int, currency: string, rows: int, refs: list<string>}> $quarantine source_type => итог
     * @param array{revision: int, facts: int, totalMinor: int, canonMinor: int, quarantineMinor: int, currency: string, unresolved: int} $day
     */
    public function saveDay(string $companyId, string $connectionRef, string $date, string $rulesVersion, array $day, array $quarantine): void
    {
        $key = ['company' => $companyId, 'connection' => $connectionRef, 'date' => $date];
        $now = $this->now();

        $this->connection->executeStatement('DELETE FROM moz_quarantine WHERE company_id = :company AND connection_ref = :connection AND operation_date = :date', $key);
        foreach ($quarantine as $sourceType => $row) {
            $this->connection->executeStatement(
                'INSERT INTO moz_quarantine (company_id, connection_ref, operation_date, source_type, amount_minor, currency, row_count, source_refs, quarantined_at)
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
            'INSERT INTO moz_day (company_id, connection_ref, operation_date, facts_revision, rules_version, fact_count, total_minor, canon_minor, quarantine_minor, currency, unresolved_listing_count, translated_at)
             VALUES (:company, :connection, :date, :revision, :rules, :facts, :total, :canon, :quarantine, :currency, :unresolved, :now)
             ON CONFLICT (company_id, connection_ref, operation_date) DO UPDATE SET
                facts_revision = EXCLUDED.facts_revision, rules_version = EXCLUDED.rules_version, fact_count = EXCLUDED.fact_count,
                total_minor = EXCLUDED.total_minor, canon_minor = EXCLUDED.canon_minor, quarantine_minor = EXCLUDED.quarantine_minor,
                currency = EXCLUDED.currency, unresolved_listing_count = EXCLUDED.unresolved_listing_count, translated_at = EXCLUDED.translated_at',
            $key + [
                'revision' => $day['revision'],
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
     * @return array<string, array{revision: int, rules: string, unresolved: int}>
     */
    public function dayStates(): array
    {
        $states = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT company_id, connection_ref, operation_date, facts_revision, rules_version, unresolved_listing_count FROM moz_day',
        ) as $row) {
            $states[self::dayKey((string) $row['company_id'], (string) $row['connection_ref'], (string) $row['operation_date'])] = [
                'revision' => (int) $row['facts_revision'],
                'rules' => (string) $row['rules_version'],
                'unresolved' => (int) $row['unresolved_listing_count'],
            ];
        }

        return $states;
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
            'SELECT DISTINCT q.company_id, q.connection_ref, q.operation_date FROM moz_quarantine q
             JOIN moz_source_type t ON t.source_type = q.source_type WHERE t.kind IS NOT NULL',
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
            'SELECT q.source_type, count(*)::int AS days, sum(q.amount_minor)::bigint AS amount_minor FROM moz_quarantine q
             LEFT JOIN moz_source_type t ON t.source_type = q.source_type
             WHERE t.kind IS NULL AND q.quarantined_at < :before
             GROUP BY q.source_type ORDER BY q.source_type',
            ['before' => $before->format('Y-m-d H:i:s')],
        );

        return $rows;
    }

    public static function dayKey(string $companyId, string $connectionRef, string $date): string
    {
        return $companyId.'|'.$connectionRef.'|'.$date;
    }

    public static function seedKind(string $sourceType): ?OperationKind
    {
        return 1 === preg_match('/^ozon:service:(\d+)$/', $sourceType, $m) ? (OzonAccrualTypeSeed::TYPES[(int) $m[1]][1] ?? null) : null;
    }

    public static function seedName(string $sourceType): string
    {
        if (1 === preg_match('/^ozon:service:(\d+)$/', $sourceType, $m)) {
            return OzonAccrualTypeSeed::TYPES[(int) $m[1]][0] ?? 'Тип Ozon '.$m[1];
        }
        if (1 === preg_match('/^ozon:field:(\w+)$/', $sourceType, $m)) {
            return OzonAccrualTypeSeed::FIELDS[$m[1]] ?? $m[1];
        }

        return $sourceType;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
