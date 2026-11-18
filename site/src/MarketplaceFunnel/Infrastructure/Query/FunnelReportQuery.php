<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Infrastructure\Query;

use App\MarketplaceFunnel\Domain\FunnelRow;
use Doctrine\DBAL\Connection;

/**
 * Чтение таблицы воронки для экрана: всегда в пределах компании.
 */
final readonly class FunnelReportQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Суммы ступеней по товарам за период. SUM по одним NULL даёт NULL — «нет данных» сохраняется.
     *
     * @return list<array{channel: string, sku: string, values: array<string, int|null>}>
     */
    public function bySku(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $channel): array
    {
        $sums = implode(', ', array_map(static fn (string $c): string => "SUM($c) AS $c", FunnelRow::COLUMNS));
        $rows = $this->connection->fetchAllAssociative(
            "SELECT channel, marketplace_sku, $sums
             FROM mfn_row
             WHERE company_id = :company AND operation_date BETWEEN :from AND :to AND source = :source
               AND (CAST(:channel AS VARCHAR) IS NULL OR channel = :channel)
             GROUP BY channel, marketplace_sku",
            ['company' => $companyId, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'source' => FunnelStore::SOURCE_TOTAL, 'channel' => $channel],
        );

        return array_map(static function (array $row): array {
            $values = [];
            foreach (FunnelRow::COLUMNS as $column) {
                $values[$column] = null === $row[$column] ? null : (int) $row[$column];
            }

            return ['channel' => (string) $row['channel'], 'sku' => (string) $row['marketplace_sku'], 'values' => $values];
        }, $rows);
    }

    /**
     * Переведённые дни периода: всего, без данных по тарифу и каналы компании.
     *
     * @return array{days: int, unavailableDays: int, channels: list<string>}
     */
    public function coverage(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $channel): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS days, COUNT(*) FILTER (WHERE NOT available) AS unavailable
             FROM mfn_day
             WHERE company_id = :company AND operation_date BETWEEN :from AND :to
               AND (CAST(:channel AS VARCHAR) IS NULL OR channel = :channel)',
            ['company' => $companyId, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'channel' => $channel],
        );

        return [
            'days' => (int) ($row['days'] ?? 0),
            'unavailableDays' => (int) ($row['unavailable'] ?? 0),
            'channels' => array_map('strval', $this->connection->fetchFirstColumn('SELECT DISTINCT channel FROM mfn_day WHERE company_id = :company ORDER BY channel', ['company' => $companyId])),
        ];
    }
}
