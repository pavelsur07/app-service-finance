<?php

declare(strict_types=1);

namespace App\MoySklad\Command;

use App\Company\Facade\CompanyFacade;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\MoySklad\Application\Action\StartMappingBulkJobAction;
use App\MoySklad\Application\Command\StartMappingBulkJobCommand;
use App\MoySklad\Exception\ListingMappingException;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Автоприменение: пакет scope cabinet (только однозначные совпадения) для включённых активных подключений.
 * id пакета = UUIDv5(scope, московская дата) — повторный запуск в те же сутки пакет не дублирует.
 * Автор — включивший пользователь; без права записи маркетплейса пакет не создаётся.
 */
#[AsCommand(name: 'app:moysklad:mapping-auto-apply', description: 'Автоприменение однозначных предложений маппинга МойСклад.')]
final class AutoApplyMappingsCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly StartMappingBulkJobAction $start, private readonly CompanyFacade $companies,
        private readonly ClockInterface $clock, private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $date = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d');
        $rows = $this->db->fetchAllAssociative('SELECT a.company_id, a.connection_id, a.marketplace, a.actor_user_id, a.size_characteristic_id
            FROM moysklad_mapping_auto_apply a JOIN moysklad_connections c ON c.company_id=a.company_id AND c.id=a.connection_id
            WHERE a.enabled AND c.is_active ORDER BY a.company_id, a.connection_id, a.marketplace');
        $started = 0;
        foreach ($rows as $row) {
            $context = ['companyId' => $row['company_id'], 'connectionId' => $row['connection_id'], 'marketplace' => $row['marketplace']];
            if (!$this->companies->userHasModuleAccess($row['company_id'], $row['actor_user_id'], Module::MARKETPLACE, AccessLevel::WRITE)) {
                $this->logger->warning('MoySklad auto-apply skipped: author lost marketplace write access.', $context + ['reason' => 'auto_apply_actor_forbidden']);
                continue;
            }
            $jobId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'moysklad-mapping-auto-apply:'.$row['company_id'].':'.$row['connection_id'].':'.$row['marketplace'].':'.$date)->toString();
            try {
                $started += (int) ($this->start)(new StartMappingBulkJobCommand($row['company_id'], $row['actor_user_id'], $row['connection_id'], $row['marketplace'],
                    $jobId, 'cabinet', [], 'auto', $row['size_characteristic_id']));
            } catch (ListingMappingException $error) {
                $this->logger->info('MoySklad auto-apply postponed.', $context + ['reason' => $error->reason]);
            }
        }
        $output->writeln(sprintf('Пакетов автоприменения: %d', $started));

        return Command::SUCCESS;
    }
}
