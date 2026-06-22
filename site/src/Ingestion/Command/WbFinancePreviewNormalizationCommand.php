<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Source\Wildberries\WbFinancePreviewResult;
use App\Ingestion\Application\Source\Wildberries\WbFinancePreviewTransaction;
use App\Ingestion\Application\Source\Wildberries\WbFinanceSalesReportDetailedPreviewMapper;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
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
    name: 'app:ingestion:wb-finance:preview-normalization',
    description: 'Previews Wildberries finance canonical mapping from stored raw records without writing transactions.',
)]
final class WbFinancePreviewNormalizationCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RawStorageFacade $rawStorageFacade,
        private readonly WbFinanceSalesReportDetailedPreviewMapper $mapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date YYYY-MM-DD.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date YYYY-MM-DD.')
            ->addOption('shop-ref', null, InputOption::VALUE_REQUIRED, 'Optional shop reference.')
            ->addOption('raw-limit', null, InputOption::VALUE_REQUIRED, 'Raw records to scan, 1..500.', 100)
            ->addOption('raw-row-limit', null, InputOption::VALUE_REQUIRED, 'Rows to scan per raw record, 1..100000.', 100000)
            ->addOption('sample-limit', null, InputOption::VALUE_REQUIRED, 'Mismatch/unknown sample rows, 1..100.', 20);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $companyId = $this->requiredUuidOption($input, 'company-id');
            [$from, $to] = $this->requiredDateWindow($input);
            $shopRef = $this->optionalStringOption($input, 'shop-ref');
            $rawLimit = $this->intOption($input, 'raw-limit', 1, 500);
            $rawRowLimit = $this->intOption($input, 'raw-row-limit', 1, 100000);
            $sampleLimit = $this->intOption($input, 'sample-limit', 1, 100);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $rawRecords = $this->rawRecords($companyId, $from, $to, $shopRef, $rawLimit);
        $result = $this->preview($companyId, $rawRecords, $rawRowLimit);

        $io->title('Wildberries finance normalization preview');
        $this->printRawRecords($io, $rawRecords);
        $this->printTransactionSummary($io, $result);
        $this->printDailyNet($io, $result);
        $this->printPayoutChecks($io, $result, $sampleLimit);
        $this->printUnknownRows($io, $result, $sampleLimit);

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
        $conditions = [
            'r.company_id = :companyId',
            'r.source = :source',
            'r.resource_type = :resourceType',
            "(
                (j.window_from IS NOT NULL AND j.window_from <= :toDate AND COALESCE(j.window_to, j.window_from) >= :fromDate)
                OR (j.window_from IS NULL AND r.fetched_at >= :fromAt AND r.fetched_at < :toExclusive)
            )",
        ];
        $params = [
            'companyId' => $companyId,
            'source' => IngestSource::WILDBERRIES->value,
            'resourceType' => WbResourceType::FINANCE_SALES_REPORT_DETAILED,
            'fromDate' => $from->format('Y-m-d'),
            'toDate' => $to->format('Y-m-d'),
            'fromAt' => $from->format('Y-m-d 00:00:00'),
            'toExclusive' => $to->modify('+1 day')->format('Y-m-d 00:00:00'),
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
                        COALESCE(j.window_from::text, DATE(r.fetched_at)::text) AS record_date
                 FROM ingest_raw_records r
                 LEFT JOIN ingest_sync_jobs j ON j.id::text = r.sync_job_id AND j.company_id = r.company_id
                 WHERE %s
                 ORDER BY record_date ASC, r.fetched_at ASC, r.created_at ASC
                 LIMIT %d',
                implode(' AND ', $conditions),
                $limit,
            ),
            $params,
        );
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     */
    private function preview(string $companyId, array $rawRecords, int $rowLimit): WbFinancePreviewResult
    {
        $transactions = [];
        $rowChecks = [];
        $unknownRows = [];
        $scannedRows = 0;
        $emptyRows = 0;

        foreach ($rawRecords as $rawRecord) {
            $rows = [];
            $recordRows = 0;
            foreach ($this->rawStorageFacade->read((string) $rawRecord['id'], $companyId) as $row) {
                if ($recordRows >= $rowLimit) {
                    break;
                }

                ++$recordRows;
                $rows[] = $row;
            }

            $result = $this->mapper->preview($companyId, $rows);
            array_push($transactions, ...$result->transactions);
            array_push($rowChecks, ...$result->rowChecks);
            array_push($unknownRows, ...$result->unknownRows);
            $scannedRows += $result->scannedRows;
            $emptyRows += $result->emptyRows;
        }

        return new WbFinancePreviewResult($transactions, $rowChecks, $unknownRows, $scannedRows, $emptyRows);
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     */
    private function printRawRecords(SymfonyStyle $io, array $rawRecords): void
    {
        $io->section('Stored raw records');
        if ([] === $rawRecords) {
            $io->writeln('No raw records found for the selected period.');

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

    private function printTransactionSummary(SymfonyStyle $io, WbFinancePreviewResult $result): void
    {
        $io->section('Mapped transaction summary');
        $io->writeln(sprintf('Scanned rows: %d, empty markers: %d, mapped transactions: %d', $result->scannedRows, $result->emptyRows, count($result->transactions)));

        if ([] === $result->transactions) {
            $io->writeln('No transactions would be mapped.');

            return;
        }

        $summary = [];
        foreach ($result->transactions as $transaction) {
            $key = $transaction->type->value.':'.$transaction->direction->value;
            $summary[$key] ??= [
                'type' => $transaction->type->value,
                'direction' => $transaction->direction->value,
                'count' => 0,
                'amountMinor' => 0,
            ];
            ++$summary[$key]['count'];
            $summary[$key]['amountMinor'] += $transaction->amountMinor;
        }

        uasort($summary, static fn (array $a, array $b): int => [$a['type'], $a['direction']] <=> [$b['type'], $b['direction']]);
        $io->table(
            ['type', 'direction', 'txCount', 'totalRub'],
            array_map(fn (array $row): array => [
                (string) $row['type'],
                (string) $row['direction'],
                (string) $row['count'],
                $this->minorToRub((int) $row['amountMinor']),
            ], array_values($summary)),
        );
    }

    private function printDailyNet(SymfonyStyle $io, WbFinancePreviewResult $result): void
    {
        $io->section('Mapped net by occurred date');
        if ([] === $result->transactions) {
            $io->writeln('No rows.');

            return;
        }

        $byDate = [];
        foreach ($result->transactions as $transaction) {
            $date = $transaction->occurredAt->format('Y-m-d');
            $byDate[$date] ??= ['count' => 0, 'netMinor' => 0];
            ++$byDate[$date]['count'];
            $byDate[$date]['netMinor'] += $transaction->signedAmountMinor();
        }

        ksort($byDate);
        $io->table(
            ['date', 'txCount', 'netRub'],
            array_map(fn (string $date, array $row): array => [
                $date,
                (string) $row['count'],
                $this->minorToRub((int) $row['netMinor']),
            ], array_keys($byDate), $byDate),
        );
    }

    private function printPayoutChecks(SymfonyStyle $io, WbFinancePreviewResult $result, int $sampleLimit): void
    {
        $io->section('Sale/refund row payout checks');
        if ([] === $result->rowChecks) {
            $io->writeln('No sale/refund payout checks.');

            return;
        }

        $expectedMinor = 0;
        $actualMinor = 0;
        $mismatches = [];
        foreach ($result->rowChecks as $check) {
            $expectedMinor += $check->expectedNetMinor;
            $actualMinor += $check->actualNetMinor;
            if (0 !== $check->deltaMinor) {
                $mismatches[] = $check;
            }
        }

        $io->table(
            ['checks', 'mismatches', 'expectedForPayRub', 'mappedNetRub', 'deltaRub'],
            [[
                (string) count($result->rowChecks),
                (string) count($mismatches),
                $this->minorToRub($expectedMinor),
                $this->minorToRub($actualMinor),
                $this->minorToRub($actualMinor - $expectedMinor),
            ]],
        );

        if ([] === $mismatches) {
            return;
        }

        $io->section('Payout mismatch sample');
        $io->table(
            ['date', 'rowKey', 'sellerOperName', 'docTypeName', 'txCount', 'expectedRub', 'actualRub', 'deltaRub'],
            array_map(fn (object $check): array => [
                (string) $check->date,
                (string) $check->rowKey,
                (string) $check->sellerOperName,
                (string) $check->docTypeName,
                (string) $check->transactionCount,
                $this->minorToRub((int) $check->expectedNetMinor),
                $this->minorToRub((int) $check->actualNetMinor),
                $this->minorToRub((int) $check->deltaMinor),
            ], array_slice($mismatches, 0, $sampleLimit)),
        );
    }

    private function printUnknownRows(SymfonyStyle $io, WbFinancePreviewResult $result, int $sampleLimit): void
    {
        $io->section('Unknown non-mapped operation rows');
        if ([] === $result->unknownRows) {
            $io->writeln('No unknown operation rows.');

            return;
        }

        $io->writeln(sprintf('Unknown rows: %d', count($result->unknownRows)));
        $io->table(
            ['date', 'rowKey', 'sellerOperName', 'docTypeName', 'nonZeroKnownFields'],
            array_map(static fn (object $row): array => [
                (string) $row->date,
                (string) $row->rowKey,
                (string) $row->sellerOperName,
                (string) $row->docTypeName,
                implode(', ', $row->nonZeroFields),
            ], array_slice($result->unknownRows, 0, $sampleLimit)),
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

    private function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $value = trim((string) $input->getOption($name));

        return '' === $value ? null : $value;
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

    private function minorToRub(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
