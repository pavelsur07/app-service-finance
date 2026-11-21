<?php

declare(strict_types=1);

namespace App\MoySklad\Command;

use App\MoySklad\Message\ApplyMappingBulkJobMessage;
use App\MoySklad\Message\UndoMappingBulkJobMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** Страховка пакетов: повторный dispatch queued/running пакетов и отмен без heartbeat дольше 10 минут. Повтор безопасен. */
#[AsCommand(name: 'app:moysklad:mapping-bulk:requeue', description: 'Переотправка зависших пакетов принятия предложений МойСклад.')]
final class RequeueMappingBulkJobsCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly MessageBusInterface $bus, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $before = $this->clock->now()->modify('-10 minutes')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        $rows = $this->db->fetchAllAssociative("SELECT company_id, id, status IN ('queued','running') AS apply FROM moysklad_mapping_bulk_jobs
            WHERE heartbeat_at < ? AND (status IN ('queued','running') OR undo_status IN ('queued','running')) ORDER BY heartbeat_at LIMIT 100", [$before]);
        foreach ($rows as $row) {
            $this->bus->dispatch($row['apply'] ? new ApplyMappingBulkJobMessage($row['company_id'], $row['id']) : new UndoMappingBulkJobMessage($row['company_id'], $row['id']));
        }
        $output->writeln(sprintf('Переотправлено пакетов: %d', count($rows)));

        return Command::SUCCESS;
    }
}
