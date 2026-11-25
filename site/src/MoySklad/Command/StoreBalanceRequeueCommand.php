<?php

declare(strict_types=1);

namespace App\MoySklad\Command;

use App\MoySklad\Message\ValueStoreStocksMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Страховка оценки складов: последний completed-снимок подключения с назначениями без прогона (потерян dispatch),
 * зависший `valuing`/`posting` (воркер упал между фазами) или временный сбой/частичная проводка старше 30 минут — повторная постановка (до 6 попыток).
 */
#[AsCommand(name: 'app:moysklad:store-balance:requeue', description: 'Повторная постановка оценки складов МойСклад и проводки в Баланс.')]
final class StoreBalanceRequeueCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly MessageBusInterface $bus, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $rows = $this->db->fetchAllAssociative("SELECT latest.company_id, latest.connection_id, latest.id AS snapshot_id
            FROM (SELECT DISTINCT ON (company_id, connection_id) company_id, connection_id, id, completed_at FROM moysklad_stock_snapshots
                  WHERE status = 'completed' AND completed_at >= :since ORDER BY company_id, connection_id, completed_at DESC, id DESC) latest
            JOIN moysklad_connections c ON c.company_id = latest.company_id AND c.id = latest.connection_id AND c.is_active
            LEFT JOIN moysklad_store_balance_runs r ON r.company_id = latest.company_id AND r.snapshot_id = latest.id
            WHERE EXISTS (SELECT 1 FROM moysklad_store_balance_links k WHERE k.company_id = latest.company_id AND k.connection_id = latest.connection_id AND NOT k.excluded)
              AND latest.completed_at < :stale
              AND (r.snapshot_id IS NULL OR (r.attempts < 6 AND COALESCE(r.finished_at, r.created_at) < :stale
                   AND (r.status IN ('valuing', 'posting', 'partial')
                   OR (r.status = 'valued' AND EXISTS (SELECT 1 FROM moysklad_store_balance_settings st WHERE st.company_id = r.company_id AND st.connection_id = r.connection_id AND st.enabled)) OR (r.status = 'failed' AND r.error_reason IN ('temporary', 'rate_limited')))))
            ORDER BY latest.company_id, latest.connection_id", [
            'since' => $now->modify('-2 days')->format('Y-m-d H:i:s.v'),
            'stale' => $now->modify('-30 minutes')->format('Y-m-d H:i:s.v'),
        ]);
        foreach ($rows as $row) {
            $this->bus->dispatch(new ValueStoreStocksMessage((string) $row['company_id'], (string) $row['connection_id'], (string) $row['snapshot_id']));
        }
        $output->writeln(sprintf('Оценок складов поставлено повторно: %d', count($rows)));

        return Command::SUCCESS;
    }
}
