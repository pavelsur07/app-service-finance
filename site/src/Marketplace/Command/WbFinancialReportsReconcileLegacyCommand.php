<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:marketplace:wb-financial-reports:reconcile-legacy',
    description: 'Reconcile legacy WB data into daily sync statuses without reloading already covered days',
)]
final class WbFinancialReportsReconcileLegacyCommand extends Command
{
    use LockableTrait;

    private const REPORT_TYPE = 'sales_report';
    private const API_ENDPOINT = 'wildberries::finance-sales-reports-detailed';
    private const LEGACY_GENERATED_ENDPOINT = 'legacy_generated_rows';
    private const FLUSH_BATCH_SIZE = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly WbFinancialReportPeriodResolver $periodResolver,
        private readonly MarketplaceFinancialReportSyncStatusRepository $syncStatusRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_OPTIONAL)
            ->addOption('connection-id', null, InputOption::VALUE_OPTIONAL)
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Y-m-d; default current year start')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'Y-m-d; default yesterday')
            ->addOption('dry-run', null, InputOption::VALUE_NONE)
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limit active connections');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->note('Command is already running.');

            return Command::SUCCESS;
        }

        try {
            $companyId = $this->normalizeOptional((string) $input->getOption('company-id'));
            $connectionId = $this->normalizeOptional((string) $input->getOption('connection-id'));
            $fromOption = $this->normalizeOptional((string) $input->getOption('from'));
            $toOption = $this->normalizeOptional((string) $input->getOption('to'));
            $dryRun = (bool) $input->getOption('dry-run');
            $limit = (int) ((string) $input->getOption('limit') ?: '0');

            if ($limit < 0) {
                $io->error('Option --limit must be greater than or equal to 0.');

                return Command::FAILURE;
            }

            if (null !== $companyId && !Uuid::isValid($companyId)) {
                $io->error('Option --company-id must be valid UUID.');

                return Command::FAILURE;
            }

            if (null !== $connectionId && !Uuid::isValid($connectionId)) {
                $io->error('Option --connection-id must be valid UUID.');

                return Command::FAILURE;
            }

            $from = null !== $fromOption
                ? $this->periodResolver->normalizeBusinessDate($fromOption)
                : $this->periodResolver->currentYearStart();
            $to = null !== $toOption
                ? $this->periodResolver->normalizeBusinessDate($toOption)
                : $this->periodResolver->yesterday();

            if ($from > $to) {
                $io->error('Option --from must be less than or equal to --to.');

                return Command::FAILURE;
            }

            $connections = $this->findActiveConnections($companyId, $connectionId, $limit);
            $days = $this->periodResolver->daysBetween($from, $to);

            $stats = [
                'already_status' => 0,
                'reconciled_from_raw' => 0,
                'reconciled_from_generated_rows' => 0,
                'left_missing' => 0,
                'skipped_in_flight_conflict' => 0,
            ];

            $created = 0;
            foreach ($connections as $conn) {
                foreach ($days as $day) {
                    $existing = $this->syncStatusRepository->findByConnectionAndDate($conn['connection_id'], $conn['company_id'], $day, self::REPORT_TYPE);
                    if ($existing instanceof MarketplaceFinancialReportSyncStatus) {
                        ++$stats['already_status'];

                        continue;
                    }

                    $raw = $this->findDailyRaw($conn['company_id'], $day);
                    if (null !== $raw) {
                        ++$stats['reconciled_from_raw'];

                        if (!$dryRun) {
                            $status = $this->buildStatusFromRaw($conn['company_id'], $conn['connection_id'], $day, $raw);
                            if (in_array($status->getStatus(), [FinancialReportSyncStatus::PROCESSING, FinancialReportSyncStatus::CONFLICT], true)) {
                                ++$stats['skipped_in_flight_conflict'];
                            }
                            $this->syncStatusRepository->save($status);
                            ++$created;
                            $this->flushBatch($created);
                        }

                        continue;
                    }

                    if ($this->hasGeneratedRows($conn['company_id'], $day)) {
                        ++$stats['reconciled_from_generated_rows'];
                        if (!$dryRun) {
                            $status = new MarketplaceFinancialReportSyncStatus(
                                Uuid::uuid7()->toString(),
                                $conn['company_id'],
                                $conn['connection_id'],
                                MarketplaceType::WILDBERRIES,
                                self::REPORT_TYPE,
                                self::LEGACY_GENERATED_ENDPOINT,
                                $day,
                            );
                            $status->markLoading(FinancialReportSyncMode::MISSING);
                            $status->markSuccess();
                            $this->syncStatusRepository->save($status);
                            ++$created;
                            $this->flushBatch($created);
                        }

                        continue;
                    }

                    ++$stats['left_missing'];
                }
            }

            if (!$dryRun) {
                $this->entityManager->flush();
            }

            $io->table(['metric', 'count'], [
                ['already_status', (string) $stats['already_status']],
                ['reconciled_from_raw', (string) $stats['reconciled_from_raw']],
                ['reconciled_from_generated_rows', (string) $stats['reconciled_from_generated_rows']],
                ['left_missing', (string) $stats['left_missing']],
                ['skipped_in_flight/conflict', (string) $stats['skipped_in_flight_conflict']],
            ]);

            $io->success($dryRun ? 'Dry-run finished.' : 'Reconciliation finished.');
            $io->writeln('Next step: app:marketplace:wb-financial-reports:sync --mode=missing --max-days=10');

            return Command::SUCCESS;
        } finally {
            $this->release();
        }
    }

    private function buildStatusFromRaw(string $companyId, string $connectionId, DateTimeImmutable $day, array $raw): MarketplaceFinancialReportSyncStatus
    {
        $apiEndpoint = (string) ($raw['api_endpoint'] ?? self::API_ENDPOINT);
        $recordsCount = max(0, (int) ($raw['records_count'] ?? 0));
        $processingStatus = is_string($raw['processing_status'] ?? null) ? $raw['processing_status'] : null;

        $status = new MarketplaceFinancialReportSyncStatus(
            Uuid::uuid7()->toString(),
            $companyId,
            $connectionId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            $apiEndpoint,
            $day,
        );

        $status->markLoading(FinancialReportSyncMode::MISSING);
        $status->markRawLoaded((string) $raw['id'], $recordsCount, null);

        if ('completed' === $processingStatus) {
            $status->markSuccess();

            return $status;
        }

        if ('failed' === $processingStatus) {
            $status->markFailedRetryable('legacy_reconciled', 'Legacy raw failed processing.', null, null, null);

            return $status;
        }

        if (in_array($processingStatus, ['pending', 'running'], true)) {
            $syncedAt = $this->parseSyncedAt($raw['synced_at'] ?? null);
            if (null !== $syncedAt && $syncedAt > new DateTimeImmutable('-6 hours')) {
                $status->markProcessing();
            } else {
                $status->markConflict('legacy_reconciled', 'Legacy raw in-flight appears stale.', null, null);
            }

            return $status;
        }

        // Unknown/null status with raw evidence: keep day non-missing and safe for manual inspection.
        if ($recordsCount > 0) {
            $status->markProcessing();

            return $status;
        }

        $status->markConflict('legacy_reconciled', 'Legacy raw has unknown status and no records.', null, null);

        return $status;
    }

    private function parseSyncedAt(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function flushBatch(int $created): void
    {
        if (0 === $created % self::FLUSH_BATCH_SIZE) {
            $this->entityManager->flush();
        }
    }

    private function normalizeOptional(string $value): ?string
    {
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    private function findActiveConnections(?string $companyId, ?string $connectionId, int $limit): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('mc.id AS connection_id', 'mc.company_id')
            ->from('marketplace_connections', 'mc')
            ->where('mc.marketplace = :marketplace')
            ->andWhere('mc.connection_type = :connectionType')
            ->andWhere('mc.is_active = true')
            ->setParameter('marketplace', 'wildberries')
            ->setParameter('connectionType', 'seller')
            ->orderBy('mc.created_at', 'ASC');

        if (null !== $companyId) {
            $qb->andWhere('mc.company_id = :companyId')->setParameter('companyId', $companyId);
        }
        if (null !== $connectionId) {
            $qb->andWhere('mc.id = :connectionId')->setParameter('connectionId', $connectionId);
        }
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    private function findDailyRaw(string $companyId, DateTimeImmutable $day): ?array
    {
        $sql = "SELECT id, processing_status, records_count, api_endpoint, synced_at
                FROM marketplace_raw_documents
                WHERE company_id = :companyId
                  AND marketplace = :marketplace
                  AND document_type = :documentType
                  AND period_from = :day
                  AND period_to = :day
                ORDER BY synced_at DESC
                LIMIT 1";

        $row = $this->connection->executeQuery($sql, [
            'companyId' => $companyId,
            'marketplace' => 'wildberries',
            'documentType' => self::REPORT_TYPE,
            'day' => $day->format('Y-m-d'),
        ])->fetchAssociative();

        return false === $row ? null : $row;
    }

    private function hasGeneratedRows(string $companyId, DateTimeImmutable $day): bool
    {
        $dayStart = $day->setTime(0, 0, 0);
        $nextDay = $dayStart->modify('+1 day');

        $params = [
            'companyId' => $companyId,
            'dayStart' => $dayStart->format('Y-m-d H:i:s'),
            'nextDay' => $nextDay->format('Y-m-d H:i:s'),
            'marketplace' => 'wildberries',
        ];

        foreach ([
            'SELECT 1 FROM marketplace_sales WHERE company_id = :companyId AND sale_date >= :dayStart AND sale_date < :nextDay AND marketplace = :marketplace LIMIT 1',
            'SELECT 1 FROM marketplace_returns WHERE company_id = :companyId AND return_date >= :dayStart AND return_date < :nextDay AND marketplace = :marketplace LIMIT 1',
            'SELECT 1 FROM marketplace_costs WHERE company_id = :companyId AND cost_date >= :dayStart AND cost_date < :nextDay AND marketplace = :marketplace LIMIT 1',
        ] as $sql) {
            if (false !== $this->connection->executeQuery($sql, $params)->fetchOne()) {
                return true;
            }
        }

        return false;
    }
}
