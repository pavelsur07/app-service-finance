<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\NormalizeRawRecordAction;
use App\Ingestion\Application\Action\RecordNormalizationIssueAction;
use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Application\Command\RecordNormalizationIssueCommand;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Message\NormalizeRawRecordMessage;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Ingestion\Repository\NormalizationIssueRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:wb-finance:normalize-stored',
    description: 'Safely resets and normalizes stored Wildberries finance raw records by report date.',
)]
final class WbFinanceNormalizeStoredCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly IngestRawRecordRepository $rawRecordRepository,
        private readonly NormalizationIssueRepository $normalizationIssueRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly NormalizeRawRecordAction $normalizeRawRecordAction,
        private readonly RecordNormalizationIssueAction $recordNormalizationIssueAction,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start report date YYYY-MM-DD.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End report date YYYY-MM-DD.')
            ->addOption('shop-ref', null, InputOption::VALUE_REQUIRED, 'Optional shop reference.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Raw records to process, 1..500.', 100)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show selected records without changing them.')
            ->addOption('dispatch', null, InputOption::VALUE_NONE, 'Reset selected records to pending and dispatch async normalization messages.')
            ->addOption('execute-inline', null, InputOption::VALUE_NONE, 'Reset selected records and normalize them synchronously in this process.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $companyId = $this->requiredUuidOption($input, 'company-id');
            [$from, $to] = $this->requiredDateWindow($input);
            $shopRef = $this->optionalStringOption($input, 'shop-ref');
            $limit = $this->intOption($input, 'limit', 1, 500);
            $mode = $this->mode($input);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $rawRecords = $this->rawRecords($companyId, $from, $to, $shopRef, $limit);

        $io->title('Wildberries finance stored normalization');
        $this->printRawRecords($io, $rawRecords);

        if ([] === $rawRecords) {
            return Command::SUCCESS;
        }

        if ('dry-run' === $mode) {
            $io->note('Dry-run only. No raw records were changed and no messages were dispatched.');

            return Command::SUCCESS;
        }

        if ('dispatch' === $mode) {
            $resultRows = $this->dispatch($companyId, $rawRecords);
            $this->printActionResult($io, $resultRows);
            $io->success(sprintf('Dispatched %d Wildberries finance raw records for normalization.', count($resultRows)));

            return Command::SUCCESS;
        }

        $resultRows = $this->executeInline($companyId, $rawRecords);
        $this->printActionResult($io, $resultRows);

        $failed = array_values(array_filter($resultRows, static fn (array $row): bool => 'done' !== $row['status']));
        if ([] !== $failed) {
            $io->warning(sprintf('Inline normalization finished with %d non-done raw records.', count($failed)));

            return Command::FAILURE;
        }

        $io->success(sprintf('Normalized %d Wildberries finance raw records inline.', count($resultRows)));

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawRecords(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $shopRef,
        int $limit,
    ): array {
        $externalReportDate = "substring(r.external_id from '^wb-sales-report-detailed:([0-9]{4}-[0-9]{2}-[0-9]{2}):rrd-[0-9]+$')::date";
        $recordDate = sprintf('COALESCE(j.window_from, %s, DATE(r.fetched_at))', $externalReportDate);
        $conditions = [
            'r.company_id = :companyId',
            'r.source = :source',
            'r.resource_type = :resourceType',
            'r.normalization_status IN (:statuses)',
            "(
                (j.window_from IS NOT NULL AND j.window_from <= :toDate AND COALESCE(j.window_to, j.window_from) >= :fromDate)
                OR (j.window_from IS NULL AND {$recordDate} >= :fromDate AND {$recordDate} <= :toDate)
            )",
        ];
        $params = [
            'companyId' => $companyId,
            'source' => IngestSource::WILDBERRIES->value,
            'resourceType' => WbResourceType::FINANCE_SALES_REPORT_DETAILED,
            'statuses' => [RawNormalizationStatus::SKIPPED->value, RawNormalizationStatus::FAILED->value],
            'fromDate' => $from->format('Y-m-d'),
            'toDate' => $to->format('Y-m-d'),
        ];
        $types = [
            'statuses' => ArrayParameterType::STRING,
        ];

        if (null !== $shopRef && '' !== $shopRef) {
            $conditions[] = 'r.shop_ref = :shopRef';
            $params['shopRef'] = $shopRef;
        }

        return $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT r.id,
                        r.external_id,
                        r.shop_ref,
                        r.fetched_at,
                        r.byte_size,
                        r.normalization_status,
                        TO_CHAR(%s, \'YYYY-MM-DD\') AS record_date
                 FROM ingest_raw_records r
                 LEFT JOIN ingest_sync_jobs j ON j.id::text = r.sync_job_id AND j.company_id = r.company_id
                 WHERE %s
                 ORDER BY record_date ASC, r.fetched_at ASC, r.created_at ASC
                 LIMIT %d',
                $recordDate,
                implode(' AND ', $conditions),
                $limit,
            ),
            $params,
            $types,
        );
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     *
     * @return list<array<string, string|int>>
     */
    private function dispatch(string $companyId, array $rawRecords): array
    {
        $records = $this->resetSelectedRecordsToPending($companyId, $rawRecords);
        $this->entityManager->flush();

        $resultRows = [];
        foreach ($records as $record) {
            $this->messageBus->dispatch(new NormalizeRawRecordMessage($record->getId(), $record->getCompanyId()));
            $resultRows[] = [
                'rawId' => $record->getId(),
                'status' => RawNormalizationStatus::PENDING->value,
                'txCount' => 0,
                'openIssues' => 0,
            ];
        }

        return $resultRows;
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     *
     * @return list<array<string, string|int>>
     */
    private function executeInline(string $companyId, array $rawRecords): array
    {
        $resultRows = [];

        foreach ($rawRecords as $row) {
            $rawRecordId = (string) $row['id'];
            $record = $this->rawRecordRepository->findByIdAndCompany($rawRecordId, $companyId);
            if (null === $record || RawNormalizationStatus::DONE === $record->getNormalizationStatus()) {
                continue;
            }

            $record->markNormalizationPending();
            $this->resolveOpenIssues($companyId, $rawRecordId);
            $this->entityManager->flush();

            try {
                ($this->normalizeRawRecordAction)(new NormalizeRawRecordCommand($rawRecordId, $companyId));
            } catch (\Throwable $exception) {
                $this->markInlineFailure($record, $exception);

                $resultRows[] = [
                    'rawId' => $rawRecordId,
                    'status' => 'error',
                    'txCount' => $this->transactionCount($companyId, $rawRecordId),
                    'openIssues' => $this->openIssueCount($companyId, $rawRecordId),
                    'error' => $exception->getMessage(),
                ];

                continue;
            }

            $resultRows[] = [
                'rawId' => $rawRecordId,
                'status' => $this->normalizationStatus($companyId, $rawRecordId),
                'txCount' => $this->transactionCount($companyId, $rawRecordId),
                'openIssues' => $this->openIssueCount($companyId, $rawRecordId),
            ];
        }

        return $resultRows;
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     *
     * @return list<IngestRawRecord>
     */
    private function resetSelectedRecordsToPending(string $companyId, array $rawRecords): array
    {
        $records = [];
        foreach ($rawRecords as $row) {
            $record = $this->rawRecordRepository->findByIdAndCompany((string) $row['id'], $companyId);
            if (null === $record || RawNormalizationStatus::DONE === $record->getNormalizationStatus()) {
                continue;
            }

            $record->markNormalizationPending();
            $this->resolveOpenIssues($companyId, $record->getId());
            $records[] = $record;
        }

        return $records;
    }

    private function markInlineFailure(IngestRawRecord $record, \Throwable $exception): void
    {
        $record->markNormalizationFailed();
        ($this->recordNormalizationIssueAction)(new RecordNormalizationIssueCommand(
            companyId: $record->getCompanyId(),
            rawRecordId: $record->getId(),
            operationGroupId: null,
            kind: NormalizationIssueKind::MAPPER_FAILURE,
            details: [
                'exceptionClass' => $exception::class,
                'message' => $exception->getMessage(),
                'source' => 'wb_finance_normalize_stored_inline',
            ],
        ));
        $this->entityManager->flush();
    }

    private function resolveOpenIssues(string $companyId, string $rawRecordId): void
    {
        foreach ($this->normalizationIssueRepository->findOpenByRawRecord($companyId, $rawRecordId) as $issue) {
            $issue->markResolved();
        }
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     */
    private function printRawRecords(SymfonyStyle $io, array $rawRecords): void
    {
        $io->section('Selected raw records');
        if ([] === $rawRecords) {
            $io->writeln('No skipped or failed raw records found for the selected period.');

            return;
        }

        $io->table(
            ['date', 'rawId', 'externalId', 'shopRef', 'status', 'bytes', 'fetchedAt'],
            array_map(static fn (array $row): array => [
                (string) ($row['record_date'] ?? ''),
                (string) $row['id'],
                (string) $row['external_id'],
                (string) $row['shop_ref'],
                (string) $row['normalization_status'],
                (string) $row['byte_size'],
                (string) $row['fetched_at'],
            ], $rawRecords),
        );
    }

    /**
     * @param list<array<string, string|int>> $resultRows
     */
    private function printActionResult(SymfonyStyle $io, array $resultRows): void
    {
        $io->section('Normalization action result');
        if ([] === $resultRows) {
            $io->writeln('No records were changed.');

            return;
        }

        $io->table(
            ['rawId', 'status', 'txCount', 'openIssues', 'error'],
            array_map(static fn (array $row): array => [
                (string) $row['rawId'],
                (string) $row['status'],
                (string) $row['txCount'],
                (string) $row['openIssues'],
                (string) ($row['error'] ?? ''),
            ], $resultRows),
        );
    }

    private function normalizationStatus(string $companyId, string $rawRecordId): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT normalization_status FROM ingest_raw_records WHERE company_id = :companyId AND id = :rawRecordId',
            ['companyId' => $companyId, 'rawRecordId' => $rawRecordId],
        );
    }

    private function transactionCount(string $companyId, string $rawRecordId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_financial_transactions WHERE company_id = :companyId AND raw_record_id = :rawRecordId',
            ['companyId' => $companyId, 'rawRecordId' => $rawRecordId],
        );
    }

    private function openIssueCount(string $companyId, string $rawRecordId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :companyId AND raw_record_id = :rawRecordId AND resolved_at IS NULL',
            ['companyId' => $companyId, 'rawRecordId' => $rawRecordId],
        );
    }

    private function requiredUuidOption(InputInterface $input, string $name): string
    {
        $value = trim((string) $input->getOption($name));
        Assert::uuid($value, sprintf('Invalid --%s UUID.', $name));

        return $value;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function requiredDateWindow(InputInterface $input): array
    {
        $from = $this->dateOption($input, 'from');
        $to = $this->dateOption($input, 'to');
        if ($from > $to) {
            throw new \InvalidArgumentException('--from cannot be later than --to.');
        }

        return [$from, $to];
    }

    private function dateOption(InputInterface $input, string $name): \DateTimeImmutable
    {
        $value = trim((string) $input->getOption($name));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('--%s must be a valid YYYY-MM-DD date.', $name));
        }

        return $date;
    }

    private function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $value = trim((string) $input->getOption($name));

        return '' === $value ? null : $value;
    }

    private function intOption(InputInterface $input, string $name, int $min, int $max): int
    {
        $value = (int) $input->getOption($name);

        return max($min, min($max, $value));
    }

    private function mode(InputInterface $input): string
    {
        $modes = array_values(array_filter([
            (bool) $input->getOption('dry-run') ? 'dry-run' : null,
            (bool) $input->getOption('dispatch') ? 'dispatch' : null,
            (bool) $input->getOption('execute-inline') ? 'execute-inline' : null,
        ]));

        if (1 !== count($modes)) {
            throw new \InvalidArgumentException('Choose exactly one action: --dry-run, --dispatch, or --execute-inline.');
        }

        return $modes[0];
    }
}
