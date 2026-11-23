<?php

declare(strict_types=1);

namespace App\MoySklad\Command;

use App\MoySklad\Application\MappingDiagnosticsRefresher;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Проход по активным подключениям: пересчёт устаревших отчётов диагностики (синхронизации, ручные связи, настройки). */
#[AsCommand(name: 'app:moysklad:mapping-diagnostics:refresh', description: 'Пересчёт устаревших отчётов диагностики маппинга МойСклад.')]
final class RefreshMappingDiagnosticsCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly MappingDiagnosticsRefresher $refresher, private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queued = 0;
        foreach ($this->db->fetchAllAssociative('SELECT company_id, id FROM moysklad_connections WHERE is_active ORDER BY company_id, id') as $row) {
            foreach (['ozon', 'wildberries'] as $marketplace) {
                try {
                    $queued += (int) $this->refresher->refreshIfStale($row['company_id'], $row['id'], $marketplace);
                } catch (\Throwable $error) {
                    $this->logger->error('MoySklad mapping diagnostics refresh failed.', ['companyId' => $row['company_id'], 'connectionId' => $row['id'], 'marketplace' => $marketplace, 'exceptionClass' => $error::class]);
                }
            }
        }
        $output->writeln(sprintf('Поставлено пересчётов: %d', $queued));

        return Command::SUCCESS;
    }
}
