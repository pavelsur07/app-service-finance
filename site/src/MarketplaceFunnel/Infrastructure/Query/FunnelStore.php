<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Infrastructure\Query;

use App\MarketplaceFunnel\Domain\FunnelRow;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Таблицы `mfn_*` через DBAL. Транзакцией владеет вызывающий (команда перевода).
 */
final readonly class FunnelStore
{
    public const SOURCE_TOTAL = 'total';

    private const CHUNK = 500;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @companyScopeExempt переводчик и гейт обходят все компании (cron)
     *
     * @return array<string, array{revision: int, rules: string}> dayKey → состояние
     */
    public function dayStates(): array
    {
        $states = [];
        foreach ($this->connection->fetchAllAssociative('SELECT company_id, connection_ref, operation_date, facts_revision, rules_version FROM mfn_day') as $row) {
            $states[self::dayKey((string) $row['company_id'], (string) $row['connection_ref'], (string) $row['operation_date'])] = [
                'revision' => (int) $row['facts_revision'],
                'rules' => (string) $row['rules_version'],
            ];
        }

        return $states;
    }

    /**
     * Замена дня кабинета целиком под блокировкой дня: строки дня удаляются и пишутся заново.
     * Вызывается внутри транзакции — блокировка живёт до её конца.
     *
     * @param list<FunnelRow> $rows
     */
    public function replaceDay(string $companyId, string $connectionRef, string $channel, string $date, int $revision, bool $available, array $rows): void
    {
        $key = ['company' => $companyId, 'connection' => $connectionRef, 'date' => $date];
        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => 'mfn:'.self::dayKey($companyId, $connectionRef, $date)]);
        $this->connection->executeStatement('DELETE FROM mfn_row WHERE company_id = :company AND connection_ref = :connection AND operation_date = :date', $key);

        $columns = implode(', ', FunnelRow::COLUMNS);
        $tuple = '('.implode(', ', array_fill(0, 6 + \count(FunnelRow::COLUMNS), '?')).')';
        $control = 0;
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $params = [];
            foreach ($chunk as $row) {
                $control += $row->controlSum();
                array_push($params, $companyId, $connectionRef, $date, $row->sku, self::SOURCE_TOTAL, $channel);
                foreach (FunnelRow::COLUMNS as $column) {
                    $params[] = $row->values[$column] ?? null;
                }
            }
            $this->connection->executeStatement(
                "INSERT INTO mfn_row (company_id, connection_ref, operation_date, marketplace_sku, source, channel, $columns) VALUES ".implode(', ', array_fill(0, \count($chunk), $tuple)),
                $params,
            );
        }

        $this->connection->executeStatement(
            'INSERT INTO mfn_day (company_id, connection_ref, operation_date, channel, facts_revision, rules_version, available, row_count, control_sum, translated_at)
             VALUES (:company, :connection, :date, :channel, :revision, :rules, :available, :rows, :control, :now)
             ON CONFLICT (company_id, connection_ref, operation_date) DO UPDATE SET
                channel = EXCLUDED.channel, facts_revision = EXCLUDED.facts_revision, rules_version = EXCLUDED.rules_version, available = EXCLUDED.available,
                row_count = EXCLUDED.row_count, control_sum = EXCLUDED.control_sum, translated_at = EXCLUDED.translated_at',
            $key + [
                'channel' => $channel,
                'revision' => $revision,
                'rules' => FunnelRow::RULES_VERSION,
                'available' => $available ? 'true' : 'false',
                'rows' => \count($rows),
                'control' => $control,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Дни, у которых строки таблицы расходятся с переведённым (число строк или контрольная сумма).
     *
     * @companyScopeExempt системный гейт по всем компаниям
     *
     * @return list<string> dayKey
     */
    public function mismatchedDays(): array
    {
        $sum = implode(' + ', array_map(static fn (string $c): string => "COALESCE(r.$c, 0)", FunnelRow::COLUMNS));

        return array_map(
            static fn (array $row): string => self::dayKey((string) $row['company_id'], (string) $row['connection_ref'], (string) $row['operation_date']),
            $this->connection->fetchAllAssociative(
                "SELECT d.company_id, d.connection_ref, d.operation_date
                 FROM mfn_day d
                 LEFT JOIN mfn_row r ON r.company_id = d.company_id AND r.connection_ref = d.connection_ref AND r.operation_date = d.operation_date
                 GROUP BY d.company_id, d.connection_ref, d.operation_date, d.row_count, d.control_sum
                 HAVING COUNT(r.marketplace_sku) <> d.row_count OR COALESCE(SUM($sum), 0) <> d.control_sum
                 ORDER BY d.operation_date",
            ),
        );
    }

    public static function dayKey(string $companyId, string $connectionRef, string $date): string
    {
        return $companyId.'|'.$connectionRef.'|'.$date;
    }
}
