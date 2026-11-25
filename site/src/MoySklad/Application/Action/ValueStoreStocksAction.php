<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Facade\BalanceFacade;
use App\MoySklad\Application\StockReportPageParser;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Exception\StockSyncException;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Оценка складов по completed-снимку остатков и установка остатков счетов Баланса.
 *
 * Фазы (каждая идемпотентна, прогон под session advisory-lock подключения):
 * 1. себестоимость единиц — `report/stock/all`, HTTP вне транзакции;
 * 2. оценка складов снимка — одной транзакцией, повтор заменяет строки;
 * 3. по каждому назначенному счёту — `BalanceFacade::setAccountBalanceFromSource` (транзакция Баланса),
 *    итог счёта-дня — отдельной записью. Ключ запроса = (подключение, счёт, дата, цель): повтор → тот же документ.
 */
final readonly class ValueStoreStocksAction
{
    public const REASON = 'Остатки складов МойСклад по себестоимости';
    private const LIMIT = 1000;

    public function __construct(
        private Connection $db,
        private MoySkladConnectionWriteRepository $connections,
        private ConnectionTokenCodec $codec,
        private MoySkladClient $client,
        private StockReportPageParser $parser,
        private BalanceFacade $balance,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** @return string|null итог прогона: valued|posted|partial|skipped; null — прогон не выполнялся (занято, нет снимка или назначений) */
    public function __invoke(string $companyId, string $connectionId, string $snapshotId): ?string
    {
        $snapshot = $this->db->fetchAssociative("SELECT started_at FROM moysklad_stock_snapshots WHERE company_id = ? AND connection_id = ? AND id = ? AND status = 'completed'", [$companyId, $connectionId, $snapshotId]);
        if (false === $snapshot || !$this->hasLinks($companyId, $connectionId)) {
            return null;
        }
        $lock = ['namespace' => 'moysklad-store-balance', 'key' => $connectionId];
        if (!in_array($this->db->fetchOne('SELECT pg_try_advisory_lock(hashtext(:namespace), hashtext(:key))', $lock), [true, 1, '1', 't'], true)) {
            return null;
        }
        try {
            $date = (new \DateTimeImmutable((string) $snapshot['started_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d');
            $this->db->executeStatement("INSERT INTO moysklad_store_balance_runs (company_id, connection_id, snapshot_id, valuation_date, status, attempts, created_at)
                VALUES (?, ?, ?, ?, 'valuing', 1, ?) ON CONFLICT (company_id, snapshot_id)
                DO UPDATE SET status = 'valuing', error_reason = NULL, finished_at = NULL, attempts = moysklad_store_balance_runs.attempts + 1",
                [$companyId, $connectionId, $snapshotId, $date, $this->now()]);
            try {
                $prices = $this->prices($companyId, $connectionId);
            } catch (StockSyncException $error) {
                $this->finish($companyId, $snapshotId, 'failed', $error->category);
                throw $error;
            }
            $this->db->transactional(fn () => $this->writeValuations($companyId, $connectionId, $snapshotId, $date, $prices));

            $status = $this->post($companyId, $connectionId, $snapshotId, new \DateTimeImmutable($date));
            $this->finish($companyId, $snapshotId, $status['status'], $status['reason']);

            return $status['status'];
        } finally {
            $this->db->fetchOne('SELECT pg_advisory_unlock(hashtext(:namespace), hashtext(:key))', $lock);
        }
    }

    /**
     * Округление половины от нуля до копейки: Σ остаток × себестоимость единицы.
     */
    public static function amountMinor(string $stock, string $priceMinor): string
    {
        $value = bcmul($stock, $priceMinor, 10);

        return str_starts_with($value, '-') ? bcsub($value, '0.5', 0) : bcadd($value, '0.5', 0);
    }

    private function hasLinks(string $companyId, string $connectionId): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM moysklad_store_balance_links WHERE company_id = ? AND connection_id = ? AND NOT excluded LIMIT 1', [$companyId, $connectionId]);
    }

    /** @return array<string, string> «тип:id» → себестоимость единицы в копейках */
    private function prices(string $companyId, string $connectionId): array
    {
        $connection = $this->connections->findByIdAndCompanyId($connectionId, $companyId);
        if (null === $connection || !$connection->isActive() || ConnectionCheckStatus::CONNECTED !== $connection->getCheckStatus()) {
            throw new StockSyncException('auth');
        }
        $token = $this->codec->accessTokenFor($connection);
        if (null === $token || '' === $token) {
            throw new StockSyncException('auth');
        }
        $prices = [];
        $offset = 0;
        $expected = null;
        do {
            $page = $this->parser->parseCosts($this->client->fetchStockCostPage($token, $offset, self::LIMIT));
            if ($page->offset !== $offset || (null !== $expected && $page->size !== $expected)) {
                throw new StockSyncException('temporary');
            }
            $expected = $page->size;
            foreach ($page->rows as $row) {
                $prices[$row->assortmentType.':'.$row->assortmentExternalId] = $row->priceMinor;
            }
            $offset += $page->limit;
        } while ($offset < $expected);

        return $prices;
    }

    /** @param array<string, string> $prices */
    private function writeValuations(string $companyId, string $connectionId, string $snapshotId, string $date, array $prices): void
    {
        $stores = [];
        $lines = $this->db->iterateAssociative('SELECT l.store_external_id, l.product_external_id, l.variant_external_id, l.stock::text AS stock
            FROM moysklad_stock_snapshot_lines l JOIN moysklad_stores s ON s.company_id = l.company_id AND s.connection_id = l.connection_id AND s.external_id = l.store_external_id
            WHERE l.company_id = ? AND l.connection_id = ? AND l.snapshot_id = ? AND NOT s.archived', [$companyId, $connectionId, $snapshotId]);
        foreach ($lines as $line) {
            $store = (string) $line['store_external_id'];
            $stores[$store] ??= ['quantity' => '0', 'amount' => '0', 'unpriced' => 0];
            $stock = (string) $line['stock'];
            if (0 === bccomp($stock, '0', 10)) {
                continue;
            }
            $key = null !== $line['variant_external_id'] ? 'variant:'.$line['variant_external_id'] : 'product:'.$line['product_external_id'];
            $price = $prices[$key] ?? '0';
            $stores[$store]['quantity'] = bcadd($stores[$store]['quantity'], $stock, 10);
            $stores[$store]['amount'] = bcadd($stores[$store]['amount'], self::amountMinor($stock, $price), 0);
            if (0 === bccomp($price, '0', 10)) {
                ++$stores[$store]['unpriced'];
            }
        }
        $this->db->executeStatement('DELETE FROM moysklad_store_valuations WHERE company_id = ? AND snapshot_id = ?', [$companyId, $snapshotId]);
        foreach ($stores as $store => $value) {
            $this->db->insert('moysklad_store_valuations', ['company_id' => $companyId, 'connection_id' => $connectionId, 'snapshot_id' => $snapshotId, 'store_external_id' => $store,
                'valuation_date' => $date, 'quantity' => $value['quantity'], 'amount_minor' => $value['amount'], 'unpriced_positions' => $value['unpriced']]);
        }
        $this->db->executeStatement("UPDATE moysklad_store_balance_runs SET status = 'valued' WHERE company_id = ? AND snapshot_id = ?", [$companyId, $snapshotId]);
    }

    /** @return array{status: string, reason: ?string} */
    private function post(string $companyId, string $connectionId, string $snapshotId, \DateTimeImmutable $date): array
    {
        $settings = $this->db->fetchAssociative('SELECT enabled, counter_account_id, actor_user_id FROM moysklad_store_balance_settings WHERE company_id = ? AND connection_id = ?', [$companyId, $connectionId]);
        if (false === $settings || !$settings['enabled'] || null === $settings['counter_account_id']) {
            return ['status' => 'valued', 'reason' => null];
        }
        $targets = $this->db->fetchAllKeyValue('SELECT k.balance_account_id, COALESCE(SUM(v.amount_minor), 0)::text
            FROM moysklad_store_balance_links k
            JOIN moysklad_stores s ON s.company_id = k.company_id AND s.connection_id = k.connection_id AND s.external_id = k.store_external_id AND NOT s.archived
            LEFT JOIN moysklad_store_valuations v ON v.company_id = k.company_id AND v.snapshot_id = ? AND v.store_external_id = k.store_external_id
            WHERE k.company_id = ? AND k.connection_id = ? AND NOT k.excluded AND k.balance_account_id IS NOT NULL
            GROUP BY k.balance_account_id ORDER BY k.balance_account_id', [$snapshotId, $companyId, $connectionId]);
        $failed = 0;
        foreach ($targets as $accountId => $target) {
            $accountId = (string) $accountId;
            $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'moysklad-store-balance:'.implode('|', [$companyId, $connectionId, $accountId, $date->format('Y-m-d'), $target]))->toString();
            try {
                $operationId = $this->balance->setAccountBalanceFromSource($companyId, (string) $settings['actor_user_id'], $accountId, (string) $settings['counter_account_id'], $date, $target, self::REASON, $key);
                $this->record($companyId, $connectionId, $snapshotId, $date, $accountId, $target, null === $operationId ? 'unchanged' : 'posted', null, $operationId);
            } catch (AccessDeniedException) {
                $this->logger->warning('MoySklad store balance skipped: author lost Balance posting rights.', ['companyId' => $companyId, 'connectionId' => $connectionId, 'reason' => 'actor_forbidden']);
                foreach ($targets as $skipped => $skippedTarget) {
                    $this->record($companyId, $connectionId, $snapshotId, $date, (string) $skipped, $skippedTarget, 'skipped', 'У автора записи нет права проводить в Балансе.', null, false);
                }

                return ['status' => 'skipped', 'reason' => 'actor_forbidden'];
            } catch (BalanceLedgerException $error) {
                ++$failed;
                $this->record($companyId, $connectionId, $snapshotId, $date, $accountId, $target, 'skipped', mb_substr($error->getMessage(), 0, 255), null);
            } catch (\Throwable $error) {
                ++$failed;
                $this->logger->error('MoySklad store balance posting failed.', ['companyId' => $companyId, 'connectionId' => $connectionId, 'accountId' => $accountId, 'exception' => $error::class]);
                $this->record($companyId, $connectionId, $snapshotId, $date, $accountId, $target, 'failed', 'Техническая ошибка, повтор при следующем прогоне.', null);
            }
        }

        return 0 === $failed ? ['status' => 'posted', 'reason' => null] : ['status' => 'partial', 'reason' => null];
    }

    private function record(string $companyId, string $connectionId, string $snapshotId, \DateTimeImmutable $date, string $accountId, string $target, string $status, ?string $reason, ?string $operationId, bool $overwritePosted = true): void
    {
        // Проведённый итог дня не затирается пропуском этого же дня (повторный прогон без прав).
        $this->db->executeStatement('INSERT INTO moysklad_store_balance_postings (company_id, connection_id, valuation_date, balance_account_id, snapshot_id, target_minor, status, reason, operation_id, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (company_id, connection_id, valuation_date, balance_account_id)
            DO UPDATE SET snapshot_id = EXCLUDED.snapshot_id, target_minor = EXCLUDED.target_minor, status = EXCLUDED.status, reason = EXCLUDED.reason,
                operation_id = COALESCE(EXCLUDED.operation_id, moysklad_store_balance_postings.operation_id), updated_at = EXCLUDED.updated_at'
            .($overwritePosted ? '' : " WHERE moysklad_store_balance_postings.status <> 'posted'"),
            [$companyId, $connectionId, $date->format('Y-m-d'), $accountId, $snapshotId, $target, $status, $reason, $operationId, $this->now()]);
    }

    private function finish(string $companyId, string $snapshotId, string $status, ?string $reason): void
    {
        $this->db->executeStatement('UPDATE moysklad_store_balance_runs SET status = ?, error_reason = ?, finished_at = ? WHERE company_id = ? AND snapshot_id = ?', [$status, $reason, $this->now(), $companyId, $snapshotId]);
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
