<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Infrastructure\Query;

use App\MarketplaceEconomics\Domain\DayEconomics;
use App\MarketplaceOperations\Application\DTO\DayWindow;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;

/**
 * Таблицы `me_*` через DBAL. Замена дня целиком; транзакцией владеет вызывающий (команда).
 */
final readonly class EconomicsStore
{
    private const CHUNK = 500;
    private const COLUMNS = ['id', 'company_id', 'channel', 'connection_ref', 'operation_date', 'listing_id', 'measure', 'level', 'amount_minor', 'quantity', 'allocation_key'];

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function replaceDay(DayWindow $day, DayEconomics $economics, string $rulesVersion): void
    {
        $window = ['company' => $day->companyId, 'channel' => $day->channel, 'connection' => $day->connectionRef, 'date' => $day->date];
        // порядок блокировок: день канона (takeDay) раньше дня витрины
        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => 'me:'.$day->key()]);
        $this->connection->executeStatement(
            'DELETE FROM me_day_line WHERE company_id = :company AND channel = :channel AND connection_ref = :connection AND operation_date = :date',
            $window,
        );

        foreach (array_chunk($economics->lines, self::CHUNK) as $chunk) {
            $rows = $params = [];
            foreach ($chunk as $line) {
                $rows[] = '('.implode(', ', array_fill(0, \count(self::COLUMNS), '?')).')';
                array_push($params, Uuid::uuid7()->toString(), $day->companyId, $day->channel, $day->connectionRef, $day->date,
                    $line->listingId, $line->measure, $line->level->value, $line->amountMinor, $line->quantity, $line->allocationKey);
            }
            $this->connection->executeStatement('INSERT INTO me_day_line ('.implode(', ', self::COLUMNS).') VALUES '.implode(', ', $rows), $params);
        }

        $this->connection->executeStatement(
            'INSERT INTO me_day (company_id, channel, connection_ref, operation_date, rules_version, canon_minor, unallocated_minor, cost_price_minor, without_cost_price, line_count, currency, refreshed_at)
             VALUES (:company, :channel, :connection, :date, :rules, :canon, :unallocated, :cost, :without, :lines, :currency, :now)
             ON CONFLICT (company_id, channel, connection_ref, operation_date) DO UPDATE SET
                rules_version = EXCLUDED.rules_version, canon_minor = EXCLUDED.canon_minor, unallocated_minor = EXCLUDED.unallocated_minor,
                cost_price_minor = EXCLUDED.cost_price_minor, without_cost_price = EXCLUDED.without_cost_price, line_count = EXCLUDED.line_count,
                currency = EXCLUDED.currency, refreshed_at = EXCLUDED.refreshed_at',
            $window + [
                'rules' => $rulesVersion,
                'canon' => $economics->canonMinor,
                'unallocated' => $economics->unallocatedMinor,
                'cost' => $economics->costPriceMinor,
                'without' => $economics->withoutCostPrice,
                'lines' => \count($economics->lines),
                'currency' => $economics->currency,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Для гейта: дни, где Σ строк витрины (без себестоимости и справочных) ≠ Σ канона дня.
     *
     * @companyScopeExempt системный гейт по всем компаниям
     *
     * @return list<array{company_id: string, operation_date: string, diff_minor: int}>
     */
    public function unbalancedDays(): array
    {
        /** @var list<array{company_id: string, operation_date: string, diff_minor: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT d.company_id::text AS company_id, d.operation_date::text AS operation_date,
                    (COALESCE(SUM(l.amount_minor) FILTER (WHERE l.level NOT IN ('cost_price', 'info')), 0) - d.canon_minor)::bigint AS diff_minor
             FROM me_day d
             LEFT JOIN me_day_line l ON l.company_id = d.company_id AND l.channel = d.channel AND l.connection_ref = d.connection_ref AND l.operation_date = d.operation_date
             GROUP BY d.company_id, d.channel, d.connection_ref, d.operation_date, d.canon_minor
             HAVING COALESCE(SUM(l.amount_minor) FILTER (WHERE l.level NOT IN ('cost_price', 'info')), 0) <> d.canon_minor
             ORDER BY d.operation_date LIMIT 100",
        );

        return $rows;
    }
}
