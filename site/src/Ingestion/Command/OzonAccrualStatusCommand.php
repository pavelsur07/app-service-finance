<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:status',
    description: 'Shows Ozon accrual ingestion jobs and raw storage status.',
)]
final class OzonAccrualStatusCommand extends Command
{
    /**
     * @var list<string>
     */
    private const RESOURCE_TYPES = [
        OzonResourceType::ACCRUAL_POSTINGS,
        OzonResourceType::ACCRUAL_BY_DAY,
        OzonResourceType::ACCRUAL_TYPES,
    ];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Optional job window start date YYYY-MM-DD.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Optional job window end date YYYY-MM-DD.')
            ->addOption('resource-type', null, InputOption::VALUE_REQUIRED, 'Optional accrual resource type filter.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $companyId = $this->requiredUuidOption($input, 'company-id');
            $resourceTypes = $this->resourceTypes($input);
            [$from, $to] = $this->dateWindow($input);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title('Ozon accrual ingestion status');
        $this->printJobs($io, $companyId, $resourceTypes, $from, $to);
        $this->printRawRecords($io, $companyId, $resourceTypes, $from, $to);
        $this->printLatestErrors($io, $companyId, $resourceTypes, $from, $to);

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $resourceTypes
     */
    private function printJobs(
        SymfonyStyle $io,
        string $companyId,
        array $resourceTypes,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
    ): void {
        [$conditions, $params, $types] = $this->jobFilter($companyId, $resourceTypes, $from, $to);

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT resource_type, status, COUNT(*) AS jobs, COALESCE(SUM(progress_total), 0) AS progress_total, COALESCE(SUM(progress_done), 0) AS progress_done, MAX(updated_at) AS latest_update
                 FROM ingest_sync_jobs
                 WHERE %s
                 GROUP BY resource_type, status
                 ORDER BY resource_type, status',
                implode(' AND ', $conditions),
            ),
            $params,
            $types,
        );

        $io->section('Sync jobs');
        if ([] === $rows) {
            $io->writeln('No jobs found.');

            return;
        }

        $io->table(
            ['resourceType', 'status', 'jobs', 'progress', 'latestUpdate'],
            array_map(static fn (array $row): array => [
                (string) $row['resource_type'],
                (string) $row['status'],
                (string) $row['jobs'],
                sprintf('%s/%s', (string) $row['progress_done'], (string) $row['progress_total']),
                (string) ($row['latest_update'] ?? ''),
            ], $rows),
        );
    }

    /**
     * @param list<string> $resourceTypes
     */
    private function printRawRecords(
        SymfonyStyle $io,
        string $companyId,
        array $resourceTypes,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
    ): void {
        $conditions = [
            'r.company_id = :companyId',
            'r.source = :source',
            'r.resource_type IN (:resourceTypes)',
        ];
        $params = [
            'companyId' => $companyId,
            'source' => IngestSource::OZON->value,
            'resourceTypes' => $resourceTypes,
        ];
        $types = ['resourceTypes' => ArrayParameterType::STRING];

        if (null !== $from && null !== $to) {
            $conditions[] = '(j.id IS NULL OR j.window_from IS NULL OR j.window_to IS NULL OR (j.window_from <= :toDate AND j.window_to >= :fromDate))';
            $params['fromDate'] = $from->format('Y-m-d');
            $params['toDate'] = $to->format('Y-m-d');
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT r.resource_type,
                        COUNT(*) AS raw_records,
                        COALESCE(SUM(r.byte_size), 0) AS byte_size,
                        MAX(r.fetched_at) AS last_fetched_at,
                        COUNT(*) FILTER (WHERE r.normalization_status = 'pending') AS pending_raw,
                        COUNT(*) FILTER (WHERE r.normalization_status = 'done') AS done_raw,
                        COUNT(*) FILTER (WHERE r.normalization_status = 'failed') AS failed_raw
                 FROM ingest_raw_records r
                 LEFT JOIN ingest_sync_jobs j ON j.id::text = r.sync_job_id AND j.company_id = r.company_id
                 WHERE %s
                 GROUP BY r.resource_type
                 ORDER BY r.resource_type",
                implode(' AND ', $conditions),
            ),
            $params,
            $types,
        );

        $io->section('Raw records');
        if ([] === $rows) {
            $io->writeln('No raw records found.');

            return;
        }

        $io->table(
            ['resourceType', 'raw', 'pending', 'done', 'failed', 'bytes', 'lastFetchedAt'],
            array_map(static fn (array $row): array => [
                (string) $row['resource_type'],
                (string) $row['raw_records'],
                (string) $row['pending_raw'],
                (string) $row['done_raw'],
                (string) $row['failed_raw'],
                (string) $row['byte_size'],
                (string) ($row['last_fetched_at'] ?? ''),
            ], $rows),
        );
    }

    /**
     * @param list<string> $resourceTypes
     */
    private function printLatestErrors(
        SymfonyStyle $io,
        string $companyId,
        array $resourceTypes,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
    ): void {
        [$conditions, $params, $types] = $this->jobFilter($companyId, $resourceTypes, $from, $to);
        $conditions[] = 'last_error IS NOT NULL';

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT id, resource_type, status, updated_at, last_error
                 FROM ingest_sync_jobs
                 WHERE %s
                 ORDER BY updated_at DESC
                 LIMIT 10',
                implode(' AND ', $conditions),
            ),
            $params,
            $types,
        );

        $io->section('Latest job errors');
        if ([] === $rows) {
            $io->writeln('No job errors found.');

            return;
        }

        $io->table(
            ['jobId', 'resourceType', 'status', 'updatedAt', 'error'],
            array_map(fn (array $row): array => [
                (string) $row['id'],
                (string) $row['resource_type'],
                (string) $row['status'],
                (string) $row['updated_at'],
                $this->truncate((string) $row['last_error'], 180),
            ], $rows),
        );
    }

    private function requiredUuidOption(InputInterface $input, string $name): string
    {
        $value = trim((string) $input->getOption($name));
        Assert::uuid($value, sprintf('Invalid --%s UUID.', $name));

        return $value;
    }

    /**
     * @return list<string>
     */
    private function resourceTypes(InputInterface $input): array
    {
        $requested = trim((string) $input->getOption('resource-type'));
        if ('' === $requested) {
            return self::RESOURCE_TYPES;
        }

        if (!in_array($requested, self::RESOURCE_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported accrual resource type "%s".', $requested));
        }

        return [$requested];
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function dateWindow(InputInterface $input): array
    {
        $fromValue = trim((string) $input->getOption('from'));
        $toValue = trim((string) $input->getOption('to'));
        if ('' === $fromValue && '' === $toValue) {
            return [null, null];
        }

        if ('' === $fromValue || '' === $toValue) {
            throw new \InvalidArgumentException('Both --from and --to must be provided when filtering by window.');
        }

        $from = $this->date($fromValue, 'from');
        $to = $this->date($toValue, 'to');
        if ($from > $to) {
            throw new \InvalidArgumentException('The --from date cannot be later than --to.');
        }

        return [$from, $to];
    }

    private function date(string $value, string $name): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be a YYYY-MM-DD date.', $name));
        }

        return $date;
    }

    /**
     * @param list<string> $resourceTypes
     *
     * @return array{0: list<string>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function jobFilter(string $companyId, array $resourceTypes, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $conditions = [
            'company_id = :companyId',
            'source = :source',
            'resource_type IN (:resourceTypes)',
        ];
        $params = [
            'companyId' => $companyId,
            'source' => IngestSource::OZON->value,
            'resourceTypes' => $resourceTypes,
        ];
        $types = ['resourceTypes' => ArrayParameterType::STRING];

        if (null !== $from && null !== $to) {
            $conditions[] = '(window_from IS NULL OR window_to IS NULL OR (window_from <= :toDate AND window_to >= :fromDate))';
            $params['fromDate'] = $from->format('Y-m-d');
            $params['toDate'] = $to->format('Y-m-d');
        }

        return [$conditions, $params, $types];
    }

    private function truncate(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit).'...';
    }
}
