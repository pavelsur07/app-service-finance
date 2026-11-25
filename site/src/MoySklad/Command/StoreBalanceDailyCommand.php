<?php

declare(strict_types=1);

namespace App\MoySklad\Command;

use App\MoySklad\Message\SyncStockSnapshotMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** Снимок остатков для подключений, где включена запись складов в Баланс; оценку и проводку ставит сам снимок. */
#[AsCommand(name: 'app:moysklad:store-balance:daily', description: 'Ежедневный снимок остатков МойСклад для записи складов в Баланс.')]
final class StoreBalanceDailyCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->db->fetchAllAssociative('SELECT s.company_id, s.connection_id FROM moysklad_store_balance_settings s
            JOIN moysklad_connections c ON c.company_id = s.company_id AND c.id = s.connection_id
            WHERE s.enabled AND c.is_active ORDER BY s.company_id, s.connection_id');
        foreach ($rows as $row) {
            $this->bus->dispatch(new SyncStockSnapshotMessage((string) $row['company_id'], (string) $row['connection_id']));
        }
        $output->writeln(sprintf('Снимков остатков поставлено: %d', count($rows)));

        return Command::SUCCESS;
    }
}
