<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayPreviewMapper;
use App\Ingestion\Application\Source\Ozon\OzonAccrualPreviewTransaction;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
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
    name: 'app:ingestion:ozon-accrual:preview-normalization',
    description: 'Builds a read-only normalization preview from stored Ozon accrual by-day raw records.',
)]
final class OzonAccrualPreviewNormalizationCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RawStorageFacade $rawStorageFacade,
        private readonly OzonAccrualByDayPreviewMapper $previewMapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date YYYY-MM-DD.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date YYYY-MM-DD.')
            ->addOption('raw-limit', null, InputOption::VALUE_REQUIRED, 'Raw records to scan, 1..50.', 20)
            ->addOption('raw-row-limit', null, InputOption::VALUE_REQUIRED, 'Rows to scan per raw record, 1..50000.', 5000)
            ->addOption('sample-limit', null, InputOption::VALUE_REQUIRED, 'Preview rows to print, 1..200.', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $companyId = $this->requiredUuidOption($input, 'company-id');
            [$from, $to] = $this->requiredDateWindow($input);
            $rawLimit = $this->intOption($input, 'raw-limit', 1, 50);
            $rawRowLimit = $this->intOption($input, 'raw-row-limit', 1, 50000);
            $sampleLimit = $this->intOption($input, 'sample-limit', 1, 200);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $rawRecords = $this->rawRecords($companyId, $from, $to, $rawLimit);
        $previewRows = $this->previewMapper->preview($companyId, $this->rawRows($companyId, $rawRecords, $rawRowLimit, $from, $to), $from, $to);
        $exactMatches = $this->exactNaturalKeyMatches($companyId, $previewRows);
        $sameAmountCandidates = $this->sameAmountCandidateCounts($companyId, $from, $to);
        $canonicalGroups = $this->canonicalGroups($companyId, $from, $to);

        $io->title('Ozon accrual normalization preview');
        $io->writeln('Read-only: no canonical transactions are written.');
        $io->writeln('Preview intentionally omits sale/refund amount fields, bonus, coinvestment, sale_amount, and seller_price.');
        $this->printRawRecords($io, $rawRecords);
        $this->printSummary($io, $previewRows, $exactMatches, $sameAmountCandidates);
        $this->printPreviewSamples($io, $previewRows, $exactMatches, $sameAmountCandidates, $sampleLimit);
        $this->printGroupComparison($io, $previewRows, $canonicalGroups);
        $this->printDailyNetComparison($io, $previewRows, $canonicalGroups);

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawRecords(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
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
                'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
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
        $io->section('Stored accrual by-day raw records');
        if ([] === $rawRecords) {
            $io->writeln('No raw records found for the selected period.');

            return;
        }

        $io->table(
            ['rawId', 'externalId', 'status', 'bytes', 'fetchedAt'],
            array_map(static fn (array $row): array => [
                (string) $row['id'],
                (string) $row['external_id'],
                (string) $row['normalization_status'],
                (string) $row['byte_size'],
                (string) $row['fetched_at'],
            ], $rawRecords),
        );
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function rawRows(
        string $companyId,
        array $rawRecords,
        int $rowLimit,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): \Generator {
        foreach ($rawRecords as $rawRecord) {
            $recordRows = 0;
            foreach ($this->rawStorageFacade->read((string) $rawRecord['id'], $companyId) as $row) {
                if ($recordRows >= $rowLimit) {
                    break;
                }

                ++$recordRows;
                if (!$this->rowDateInWindow($row, $from, $to)) {
                    continue;
                }

                yield $row;
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowDateInWindow(array $row, \DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        $date = trim((string) ($row['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        return $date >= $from->format('Y-m-d') && $date <= $to->format('Y-m-d');
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $previewRows
     *
     * @return array<string, int>
     */
    private function exactNaturalKeyMatches(string $companyId, array $previewRows): array
    {
        $sourceKeys = array_values(array_unique(array_map(static fn (OzonAccrualPreviewTransaction $row): string => $row->sourceKey, $previewRows)));
        if ([] === $sourceKeys) {
            return [];
        }

        $rows = $this->connection->executeQuery(
            'SELECT external_id, type, COUNT(*) AS count
             FROM ingest_financial_transactions
             WHERE company_id = :companyId
               AND source = :source
               AND external_id IN (:sourceKeys)
             GROUP BY external_id, type',
            [
                'companyId' => $companyId,
                'source' => IngestSource::OZON->value,
                'sourceKeys' => $sourceKeys,
            ],
            [
                'sourceKeys' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $matches = [];
        foreach ($rows as $row) {
            $matches[$this->exactMatchKey((string) $row['external_id'], (string) $row['type'])] = (int) $row['count'];
        }

        return $matches;
    }

    /**
     * @return array<string, int>
     */
    private function sameAmountCandidateCounts(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DATE(occurred_at) AS date,
                    type,
                    direction,
                    amount_minor,
                    COUNT(*) AS count
             FROM ingest_financial_transactions
             WHERE company_id = :companyId
               AND source = :source
               AND occurred_at >= :fromAt
               AND occurred_at <= :toAt
             GROUP BY DATE(occurred_at), type, direction, amount_minor',
            [
                'companyId' => $companyId,
                'source' => IngestSource::OZON->value,
                'fromAt' => $from->format('Y-m-d 00:00:00'),
                'toAt' => $to->format('Y-m-d 23:59:59'),
            ],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[$this->sameAmountKey((string) $row['date'], (string) $row['type'], (string) $row['direction'], (int) $row['amount_minor'])] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * @return array<string, array{count: int, totalMinor: int}>
     */
    private function canonicalGroups(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DATE(occurred_at) AS date,
                    type,
                    direction,
                    COUNT(*) AS count,
                    COALESCE(SUM(amount_minor), 0) AS total_minor
             FROM ingest_financial_transactions
             WHERE company_id = :companyId
               AND source = :source
               AND occurred_at >= :fromAt
               AND occurred_at <= :toAt
             GROUP BY DATE(occurred_at), type, direction',
            [
                'companyId' => $companyId,
                'source' => IngestSource::OZON->value,
                'fromAt' => $from->format('Y-m-d 00:00:00'),
                'toAt' => $to->format('Y-m-d 23:59:59'),
            ],
        );

        $groups = [];
        foreach ($rows as $row) {
            $groups[$this->groupKey((string) $row['date'], (string) $row['type'], (string) $row['direction'])] = [
                'count' => (int) $row['count'],
                'totalMinor' => (int) $row['total_minor'],
            ];
        }

        return $groups;
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $previewRows
     * @param array<string, int> $exactMatches
     * @param array<string, int> $sameAmountCandidates
     */
    private function printSummary(SymfonyStyle $io, array $previewRows, array $exactMatches, array $sameAmountCandidates): void
    {
        $exactMatchRows = 0;
        $sameAmountCandidateRows = 0;

        foreach ($previewRows as $row) {
            if (($exactMatches[$this->exactMatchKey($row->sourceKey, $row->type->value)] ?? 0) > 0) {
                ++$exactMatchRows;
            }

            if (($sameAmountCandidates[$this->sameAmountKey($row->date, $row->type->value, $row->direction->value, $row->amountMinor)] ?? 0) > 0) {
                ++$sameAmountCandidateRows;
            }
        }

        $io->section('Preview summary');
        $io->table(
            ['metric', 'value'],
            [
                ['proposedRows', (string) count($previewRows)],
                ['exactNaturalKeyMatches', (string) $exactMatchRows],
                ['sameDayTypeDirectionAmountCandidates', (string) $sameAmountCandidateRows],
            ],
        );
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $previewRows
     * @param array<string, int> $exactMatches
     * @param array<string, int> $sameAmountCandidates
     */
    private function printPreviewSamples(
        SymfonyStyle $io,
        array $previewRows,
        array $exactMatches,
        array $sameAmountCandidates,
        int $sampleLimit,
    ): void {
        $io->section('Preview transaction samples');
        if ([] === $previewRows) {
            $io->writeln('No preview rows.');

            return;
        }

        $rows = [];
        foreach (array_slice($previewRows, 0, $sampleLimit) as $row) {
            $rows[] = [
                $row->date,
                $row->type->value,
                $row->direction->value,
                $this->minorToRub($row->amountMinor),
                $row->category,
                $row->component,
                $row->typeId ?? $row->field ?? '',
                $row->sourceKey,
                (string) ($exactMatches[$this->exactMatchKey($row->sourceKey, $row->type->value)] ?? 0),
                (string) ($sameAmountCandidates[$this->sameAmountKey($row->date, $row->type->value, $row->direction->value, $row->amountMinor)] ?? 0),
            ];
        }

        $io->table(
            ['date', 'type', 'direction', 'amountRub', 'category', 'component', 'typeIdOrField', 'sourceKey', 'exact', 'sameAmount'],
            $rows,
        );
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $previewRows
     * @param array<string, array{count: int, totalMinor: int}> $canonicalGroups
     */
    private function printGroupComparison(SymfonyStyle $io, array $previewRows, array $canonicalGroups): void
    {
        $previewGroups = [];
        foreach ($previewRows as $row) {
            $key = $this->groupKey($row->date, $row->type->value, $row->direction->value);
            if (!isset($previewGroups[$key])) {
                $previewGroups[$key] = [
                    'date' => $row->date,
                    'type' => $row->type->value,
                    'direction' => $row->direction->value,
                    'count' => 0,
                    'totalMinor' => 0,
                ];
            }

            ++$previewGroups[$key]['count'];
            $previewGroups[$key]['totalMinor'] += $row->amountMinor;
        }

        $keys = array_values(array_unique(array_merge(array_keys($previewGroups), array_keys($canonicalGroups))));
        sort($keys);

        $io->section('Preview vs canonical by date/type/direction');
        if ([] === $keys) {
            $io->writeln('No rows.');

            return;
        }

        $rows = [];
        foreach ($keys as $key) {
            [$date, $type, $direction] = explode('|', $key, 3);
            $previewCount = (int) ($previewGroups[$key]['count'] ?? 0);
            $previewTotal = (int) ($previewGroups[$key]['totalMinor'] ?? 0);
            $canonicalCount = (int) ($canonicalGroups[$key]['count'] ?? 0);
            $canonicalTotal = (int) ($canonicalGroups[$key]['totalMinor'] ?? 0);

            $rows[] = [
                $date,
                $type,
                $direction,
                (string) $previewCount,
                $this->minorToRub($previewTotal),
                (string) $canonicalCount,
                $this->minorToRub($canonicalTotal),
                $this->minorToRub($previewTotal - $canonicalTotal),
            ];
        }

        $io->table(['date', 'type', 'direction', 'previewCount', 'previewRub', 'canonicalCount', 'canonicalRub', 'deltaRub'], $rows);
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $previewRows
     * @param array<string, array{count: int, totalMinor: int}> $canonicalGroups
     */
    private function printDailyNetComparison(SymfonyStyle $io, array $previewRows, array $canonicalGroups): void
    {
        $previewByDate = [];
        foreach ($previewRows as $row) {
            $previewByDate[$row->date] = ($previewByDate[$row->date] ?? 0) + $row->signedAmountMinor();
        }

        $canonicalByDate = [];
        foreach ($canonicalGroups as $key => $group) {
            [$date, , $direction] = explode('|', $key, 3);
            $signed = 'out' === $direction ? -$group['totalMinor'] : $group['totalMinor'];
            $canonicalByDate[$date] = ($canonicalByDate[$date] ?? 0) + $signed;
        }

        $dates = array_values(array_unique(array_merge(array_keys($previewByDate), array_keys($canonicalByDate))));
        sort($dates);

        $io->section('Preview net vs canonical net by date');
        if ([] === $dates) {
            $io->writeln('No rows.');

            return;
        }

        $rows = [];
        foreach ($dates as $date) {
            $previewTotal = (int) ($previewByDate[$date] ?? 0);
            $canonicalTotal = (int) ($canonicalByDate[$date] ?? 0);
            $rows[] = [
                $date,
                $this->minorToRub($previewTotal),
                $this->minorToRub($canonicalTotal),
                $this->minorToRub($previewTotal - $canonicalTotal),
            ];
        }

        $io->table(['date', 'previewNetRub', 'canonicalNetRub', 'deltaRub'], $rows);
    }

    private function exactMatchKey(string $sourceKey, string $type): string
    {
        return sprintf('%s|%s', $sourceKey, $type);
    }

    private function sameAmountKey(string $date, string $type, string $direction, int $amountMinor): string
    {
        return sprintf('%s|%s|%s|%d', $date, $type, $direction, $amountMinor);
    }

    private function groupKey(string $date, string $type, string $direction): string
    {
        return sprintf('%s|%s|%s', $date, $type, $direction);
    }

    private function minorToRub(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
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
}
