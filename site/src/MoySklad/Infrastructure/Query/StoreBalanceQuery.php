<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/** Страница «Склады»: назначения, последняя оценка и итог проводки. Все запросы ограничены компанией и подключением. */
final readonly class StoreBalanceQuery
{
    public function __construct(private Connection $db)
    {
    }

    /** @return list<array{externalId: string, name: string, pathName: string, accountId: ?string, excluded: bool, quantity: ?string, amountMinor: ?string, unpriced: int, missing: int}> */
    public function stores(string $companyId, string $connectionId, ?string $snapshotId): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT s.external_id, s.name, s.path_name, k.balance_account_id, COALESCE(k.excluded, FALSE) AS excluded,
                v.quantity::text AS quantity, v.amount_minor::text AS amount_minor, COALESCE(v.unpriced_positions, 0) AS unpriced, COALESCE(v.missing_positions, 0) AS missing
            FROM moysklad_stores s
            LEFT JOIN moysklad_store_balance_links k ON k.company_id = s.company_id AND k.connection_id = s.connection_id AND k.store_external_id = s.external_id
            LEFT JOIN moysklad_store_valuations v ON v.company_id = s.company_id AND v.snapshot_id = ? AND v.store_external_id = s.external_id
            WHERE s.company_id = ? AND s.connection_id = ? AND NOT s.archived
            ORDER BY s.path_name, s.name, s.external_id', [$snapshotId, $companyId, $connectionId]);

        return array_map(static fn (array $row): array => [
            'externalId' => (string) $row['external_id'], 'name' => (string) $row['name'], 'pathName' => (string) $row['path_name'],
            'accountId' => null === $row['balance_account_id'] ? null : (string) $row['balance_account_id'], 'excluded' => (bool) $row['excluded'],
            'quantity' => null === $row['quantity'] ? null : (string) $row['quantity'], 'amountMinor' => null === $row['amount_minor'] ? null : (string) $row['amount_minor'],
            'unpriced' => (int) $row['unpriced'], 'missing' => (int) $row['missing'],
        ], $rows);
    }

    /** @return array{active: int, linked: int, excluded: int} */
    public function counts(string $companyId, string $connectionId): array
    {
        $row = $this->db->fetchAssociative('SELECT COUNT(*) AS active, COUNT(k.store_external_id) FILTER (WHERE NOT k.excluded) AS linked, COUNT(k.store_external_id) FILTER (WHERE k.excluded) AS excluded
            FROM moysklad_stores s LEFT JOIN moysklad_store_balance_links k ON k.company_id = s.company_id AND k.connection_id = s.connection_id AND k.store_external_id = s.external_id
            WHERE s.company_id = ? AND s.connection_id = ? AND NOT s.archived', [$companyId, $connectionId]) ?: [];

        return ['active' => (int) ($row['active'] ?? 0), 'linked' => (int) ($row['linked'] ?? 0), 'excluded' => (int) ($row['excluded'] ?? 0)];
    }

    /** @return array{snapshotId: string, date: string, status: string, errorReason: ?string, finishedAt: ?string}|null */
    public function latestRun(string $companyId, string $connectionId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT r.snapshot_id, r.valuation_date, r.status, r.error_reason, r.finished_at FROM moysklad_store_balance_runs r
            JOIN moysklad_stock_snapshots s ON s.company_id = r.company_id AND s.connection_id = r.connection_id AND s.id = r.snapshot_id
            WHERE r.company_id = ? AND r.connection_id = ? ORDER BY s.started_at DESC, s.id DESC LIMIT 1', [$companyId, $connectionId]);

        return false === $row ? null : ['snapshotId' => (string) $row['snapshot_id'], 'date' => (string) $row['valuation_date'], 'status' => (string) $row['status'],
            'errorReason' => null === $row['error_reason'] ? null : (string) $row['error_reason'], 'finishedAt' => null === $row['finished_at'] ? null : (string) $row['finished_at']];
    }

    /** Последняя оценённая версия — её суммы показываются, даже если более новый прогон упал. */
    public function latestValuedSnapshot(string $companyId, string $connectionId): ?string
    {
        $id = $this->db->fetchOne("SELECT r.snapshot_id FROM moysklad_store_balance_runs r
            JOIN moysklad_stock_snapshots s ON s.company_id = r.company_id AND s.connection_id = r.connection_id AND s.id = r.snapshot_id
            WHERE r.company_id = ? AND r.connection_id = ? AND r.status <> 'valuing' AND r.status <> 'failed'
            ORDER BY s.started_at DESC, s.id DESC LIMIT 1", [$companyId, $connectionId]);

        return false === $id ? null : (string) $id;
    }

    /** @return array<string, array{date: string, targetMinor: string, status: string, reason: ?string}> счёт → итог последнего дня */
    public function latestPostings(string $companyId, string $connectionId): array
    {
        $result = [];
        foreach ($this->db->fetchAllAssociative('SELECT DISTINCT ON (balance_account_id) balance_account_id, valuation_date, target_minor::text AS target_minor, status, reason
            FROM moysklad_store_balance_postings WHERE company_id = ? AND connection_id = ? ORDER BY balance_account_id, valuation_date DESC', [$companyId, $connectionId]) as $row) {
            $result[(string) $row['balance_account_id']] = ['date' => (string) $row['valuation_date'], 'targetMinor' => (string) $row['target_minor'], 'status' => (string) $row['status'],
                'reason' => null === $row['reason'] ? null : (string) $row['reason']];
        }

        return $result;
    }

    /** @return array{enabled: bool, counterAccountId: ?string, actorUserId: string}|null */
    public function settings(string $companyId, string $connectionId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT enabled, counter_account_id, actor_user_id FROM moysklad_store_balance_settings WHERE company_id = ? AND connection_id = ?', [$companyId, $connectionId]);

        return false === $row ? null : ['enabled' => (bool) $row['enabled'], 'counterAccountId' => null === $row['counter_account_id'] ? null : (string) $row['counter_account_id'], 'actorUserId' => (string) $row['actor_user_id']];
    }
}
