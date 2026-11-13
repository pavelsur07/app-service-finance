<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/** Heuristic warnings only: equal legal payments are never suppressed or merged. */
final readonly class PaymentIntakeDuplicateQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, list<string>> candidate UUID => existing ledger UUIDs
     */
    public function findMatches(string $companyId, string $moneyAccountId, array $items): array
    {
        $candidates = [];
        foreach ($items as $item) {
            $date = \is_string($item['date']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $item['date']) : false;
            if (false === $date || $date->format('Y-m-d') !== $item['date'] || !\is_string($item['amount'])
                || 1 !== preg_match('/^[0-9]{1,16}(?:\.[0-9]{1,2})?$/D', $item['amount'])
                || !\in_array($item['direction'], ['inflow', 'outflow'], true) || !\is_string($item['currency'])) {
                continue;
            }
            $candidates[] = ['item_id' => $item['id'], 'occurred_on' => $item['date'], 'amount' => $item['amount'], 'currency' => $item['currency'], 'direction' => strtoupper($item['direction'])];
        }
        if ([] === $candidates) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT candidate.item_id, array_to_json(array_agg(tx.id ORDER BY tx.id)) AS ids
            FROM jsonb_to_recordset(CAST(:items AS jsonb)) AS candidate(item_id text, occurred_on date, amount numeric, currency text, direction text)
            JOIN cash_transaction tx ON tx.company_id = :company AND tx.money_account_id = :account
                AND tx.occurred_at >= candidate.occurred_on AND tx.occurred_at < candidate.occurred_on + INTERVAL '1 day' AND tx.amount = candidate.amount
                AND tx.currency = candidate.currency AND tx.direction = candidate.direction
                AND tx.deleted_at IS NULL AND tx.is_transfer = false
            GROUP BY candidate.item_id
            SQL, ['company' => $companyId, 'account' => $moneyAccountId, 'items' => json_encode($candidates, \JSON_THROW_ON_ERROR)]);
        $matches = [];
        foreach ($rows as $row) {
            $ids = json_decode((string) $row['ids'], true, 512, \JSON_THROW_ON_ERROR);
            \assert(\is_array($ids));
            $matches[(string) $row['item_id']] = array_values(array_filter($ids, \is_string(...)));
        }

        return $matches;
    }
}
