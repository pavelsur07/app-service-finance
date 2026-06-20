<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:compare',
    description: 'Compares current Ozon canonical transactions with stored Ozon accrual raw structures.',
)]
final class OzonAccrualCompareCommand extends Command
{
    /**
     * @var list<string>
     */
    private const RESOURCE_TYPES = [
        OzonResourceType::ACCRUAL_POSTINGS,
        OzonResourceType::ACCRUAL_BY_DAY,
        OzonResourceType::ACCRUAL_TYPES,
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly RawStorageFacade $rawStorageFacade,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date YYYY-MM-DD.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date YYYY-MM-DD.')
            ->addOption('resource-type', null, InputOption::VALUE_REQUIRED, 'Accrual resource type.', OzonResourceType::ACCRUAL_POSTINGS)
            ->addOption('raw-limit', null, InputOption::VALUE_REQUIRED, 'Raw records to scan, 1..50.', 20)
            ->addOption('raw-row-limit', null, InputOption::VALUE_REQUIRED, 'Rows to scan per raw record, 1..50000.', 5000);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $companyId = $this->requiredUuidOption($input, 'company-id');
            [$from, $to] = $this->requiredDateWindow($input);
            $resourceType = $this->resourceType($input);
            $rawLimit = $this->intOption($input, 'raw-limit', 1, 50);
            $rawRowLimit = $this->intOption($input, 'raw-row-limit', 1, 50000);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title('Ozon accrual compare');
        $this->printCanonicalSummary($io, $companyId, $from, $to);
        $rawRecords = $this->rawRecords($companyId, $resourceType, $from, $to, $rawLimit);
        $this->printRawRecords($io, $rawRecords);
        $this->printRawStructureSummary($io, $companyId, $rawRecords, $rawRowLimit);

        return Command::SUCCESS;
    }

    private function printCanonicalSummary(SymfonyStyle $io, string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DATE(occurred_at) AS date,
                    type,
                    direction,
                    COUNT(*) AS tx_count,
                    COALESCE(SUM(amount_minor), 0)::numeric / 100 AS total_rub
             FROM ingest_financial_transactions
             WHERE company_id = :companyId
               AND source = :source
               AND occurred_at >= :fromAt
               AND occurred_at <= :toAt
             GROUP BY DATE(occurred_at), type, direction
             ORDER BY DATE(occurred_at), type, direction',
            [
                'companyId' => $companyId,
                'source' => IngestSource::OZON->value,
                'fromAt' => $from->format('Y-m-d 00:00:00'),
                'toAt' => $to->format('Y-m-d 23:59:59'),
            ],
        );

        $io->section('Current canonical transactions');
        if ([] === $rows) {
            $io->writeln('No canonical Ozon transactions found for the period.');

            return;
        }

        $io->table(
            ['date', 'type', 'direction', 'txCount', 'totalRub'],
            array_map(static fn (array $row): array => [
                (string) $row['date'],
                (string) $row['type'],
                (string) $row['direction'],
                (string) $row['tx_count'],
                (string) $row['total_rub'],
            ], $rows),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawRecords(
        string $companyId,
        string $resourceType,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
    ): array {
        return $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT r.id,
                        r.resource_type,
                        r.external_id,
                        r.fetched_at,
                        r.byte_size,
                        r.normalization_status
                 FROM ingest_raw_records r
                 LEFT JOIN ingest_sync_jobs j ON j.id::text = r.sync_job_id AND j.company_id = r.company_id
                 WHERE r.company_id = :companyId
                   AND r.source = :source
                   AND r.resource_type = :resourceType
                   AND (j.id IS NULL OR j.window_from IS NULL OR j.window_to IS NULL OR (j.window_from <= :toDate AND j.window_to >= :fromDate))
                 ORDER BY r.fetched_at DESC, r.created_at DESC
                 LIMIT %d',
                $limit,
            ),
            [
                'companyId' => $companyId,
                'source' => IngestSource::OZON->value,
                'resourceType' => $resourceType,
                'fromDate' => $from->format('Y-m-d'),
                'toDate' => $to->format('Y-m-d'),
            ],
        );
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     */
    private function printRawRecords(SymfonyStyle $io, array $rawRecords): void
    {
        $io->section('Stored accrual raw records');
        if ([] === $rawRecords) {
            $io->writeln('No raw records found for the selected accrual resource type and period.');

            return;
        }

        $io->table(
            ['rawId', 'resourceType', 'externalId', 'status', 'bytes', 'fetchedAt'],
            array_map(static fn (array $row): array => [
                (string) $row['id'],
                (string) $row['resource_type'],
                (string) $row['external_id'],
                (string) $row['normalization_status'],
                (string) $row['byte_size'],
                (string) $row['fetched_at'],
            ], $rawRecords),
        );
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     */
    private function printRawStructureSummary(SymfonyStyle $io, string $companyId, array $rawRecords, int $rowLimit): void
    {
        if ([] === $rawRecords) {
            return;
        }

        $keyCounts = [];
        $identifierCounts = [];
        $amountSums = [];
        $scannedRows = 0;

        foreach ($rawRecords as $rawRecord) {
            $recordRows = 0;
            foreach ($this->rawStorageFacade->read((string) $rawRecord['id'], $companyId) as $row) {
                if ($recordRows >= $rowLimit) {
                    break;
                }

                ++$recordRows;
                ++$scannedRows;

                foreach ($row as $key => $value) {
                    $keyCounts[$key] = ($keyCounts[$key] ?? 0) + 1;

                    if (preg_match('/(?:operation_id|posting_number|accrual_id|type_id|sku|order_id)/i', $key)) {
                        $identifierCounts[$key] = ($identifierCounts[$key] ?? 0) + 1;
                    }

                    if (preg_match('/(?:amount|price|sum|commission|fee|delivery|acquiring|service|charge)/i', $key)) {
                        $numeric = $this->numericValue($value);
                        if (null !== $numeric) {
                            $amountSums[$key] = ($amountSums[$key] ?? 0.0) + $numeric;
                        }
                    }
                }
            }
        }

        arsort($keyCounts);
        arsort($identifierCounts);
        arsort($amountSums);

        $io->section('Raw structure summary');
        $io->writeln(sprintf('Scanned rows: %d', $scannedRows));

        $this->printFrequencyTable($io, 'Top keys', $keyCounts);
        $this->printFrequencyTable($io, 'Identifier-like keys', $identifierCounts);
        $this->printAmountTable($io, $amountSums);
    }

    /**
     * @param array<string, int> $counts
     */
    private function printFrequencyTable(SymfonyStyle $io, string $title, array $counts): void
    {
        $io->section($title);
        if ([] === $counts) {
            $io->writeln('No matching keys found.');

            return;
        }

        $rows = [];
        foreach (array_slice($counts, 0, 20, true) as $key => $count) {
            $rows[] = [$key, (string) $count];
        }

        $io->table(['key', 'count'], $rows);
    }

    /**
     * @param array<string, float> $amountSums
     */
    private function printAmountTable(SymfonyStyle $io, array $amountSums): void
    {
        $io->section('Amount-like key sums');
        if ([] === $amountSums) {
            $io->writeln('No numeric amount-like keys found.');

            return;
        }

        $rows = [];
        foreach (array_slice($amountSums, 0, 20, true) as $key => $sum) {
            $rows[] = [$key, number_format($sum, 2, '.', '')];
        }

        $io->table(['key', 'sum'], $rows);
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
        $from = $this->requiredDateOption($input, 'from');
        $to = $this->requiredDateOption($input, 'to');
        if ($from > $to) {
            throw new \InvalidArgumentException('The --from date cannot be later than --to.');
        }

        return [$from, $to];
    }

    private function requiredDateOption(InputInterface $input, string $name): \DateTimeImmutable
    {
        $value = trim((string) $input->getOption($name));
        Assert::notEmpty($value, sprintf('The --%s option is required.', $name));

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be a YYYY-MM-DD date.', $name));
        }

        return $date;
    }

    private function resourceType(InputInterface $input): string
    {
        $value = trim((string) $input->getOption('resource-type'));
        if (!in_array($value, self::RESOURCE_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported accrual resource type "%s".', $value));
        }

        return $value;
    }

    private function intOption(InputInterface $input, string $name, int $min, int $max): int
    {
        $value = (string) $input->getOption($name);
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from %d to %d.', $name, $min, $max));
        }

        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from %d to %d.', $name, $min, $max));
        }

        return $number;
    }

    private function numericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], trim($value));
        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }
}
