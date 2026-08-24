<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Service\OzonAccrualStaleProjectionPruner;
use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayMapper;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestRawRecordRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:prune-stale-projection',
    description: 'Prunes stale canonical Ozon accrual transactions left by overlapping replacement snapshots.',
)]
final class OzonAccrualPruneStaleProjectionCommand extends Command
{
    use LockableTrait;

    private const BUSINESS_TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly Connection $connection,
        private readonly IngestRawRecordRepository $rawRecordRepository,
        private readonly RawStorageFacade $rawStorageFacade,
        private readonly OzonAccrualByDayMapper $mapper,
        private readonly OzonAccrualStaleProjectionPruner $pruner,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days-back', null, InputOption::VALUE_REQUIRED, 'Rolling window size. Used when --from/--to are omitted.', 45)
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Optional start accrual date YYYY-MM-DD. Must be paired with --to.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Optional end accrual date YYYY-MM-DD. Must be paired with --from.')
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Optional company UUID filter.')
            ->addOption('shop-ref', null, InputOption::VALUE_REQUIRED, 'Optional shop reference filter.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Raw records to scan, 1..500.', 100)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show stale canonical transactions without deleting them.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Delete stale canonical transactions.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->warning('Ozon accrual stale projection prune is already running.');

            return Command::SUCCESS;
        }

        try {
            return $this->runPrune($input, $io);
        } finally {
            $this->release();
        }
    }

    private function runPrune(InputInterface $input, SymfonyStyle $io): int
    {
        try {
            [$from, $to, $daysBack] = $this->dateWindow($input);
            $companyId = $this->optionalUuidOption($input, 'company-id');
            $shopRef = $this->optionalStringOption($input, 'shop-ref');
            $limit = $this->intOption($input, 'limit', 1, 500);
            $execute = $this->mode($input);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $rawRows = $this->rawRows($from, $to, $companyId, $shopRef, $limit);

        $io->title('Ozon accrual stale projection prune');
        $io->table(['setting', 'value'], [
            ['mode', $execute ? 'execute' : 'dry-run'],
            ['from', $from->format('Y-m-d')],
            ['to', $to->format('Y-m-d')],
            ['daysBack', null === $daysBack ? 'custom' : (string) $daysBack],
            ['companyId', $companyId ?? 'all'],
            ['shopRef', $shopRef ?? 'all'],
            ['limit', (string) $limit],
            ['rawRecords', (string) count($rawRows)],
        ]);

        if ([] === $rawRows) {
            $io->success('No done Ozon accrual by-day raw records found.');

            return Command::SUCCESS;
        }

        $totals = ['rawRecords' => count($rawRows), 'candidates' => 0, 'deleted' => 0, 'failed' => 0];
        $groupedRows = [];
        $seenDryRunTransactionIds = [];

        foreach ($rawRows as $rawRow) {
            $rawRecord = $this->rawRecordRepository->findByIdAndCompany((string) $rawRow['id'], (string) $rawRow['company_id']);
            if (!$rawRecord instanceof IngestRawRecord) {
                ++$totals['failed'];
                continue;
            }

            try {
                $rows = array_values(iterator_to_array($this->rawStorageFacade->read($rawRecord->getId(), $rawRecord->getCompanyId()), false));
                $mapped = $this->mapper->mapForCategoryMetadataRefresh($rawRecord, $rows, recordUnknownCategories: false);
                $result = $this->pruner->prune($rawRecord, $mapped, $execute, includeRows: true);
            } catch (\Throwable $exception) {
                ++$totals['failed'];
                $this->logger->warning('Ozon accrual stale projection prune failed for raw record.', [
                    'companyId' => $rawRecord->getCompanyId(),
                    'rawRecordId' => $rawRecord->getId(),
                    'exceptionClass' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
                continue;
            }

            $rows = $result->rows;
            $candidates = $result->candidates;
            if (!$execute) {
                $rows = [];
                foreach ($result->rows as $row) {
                    $transactionId = (string) $row['id'];
                    if (isset($seenDryRunTransactionIds[$transactionId])) {
                        continue;
                    }

                    $seenDryRunTransactionIds[$transactionId] = true;
                    $rows[] = $row;
                }
                $candidates = count($rows);
            }

            $totals['candidates'] += $candidates;
            $totals['deleted'] += $result->deleted;
            $this->collectGroupedRows($groupedRows, $rawRecord, $rows);
        }

        $io->section('Stale projection rows');
        $this->printGroupedRows($io, $groupedRows);

        $io->section('Summary');
        $io->table(
            ['metric', 'value'],
            array_map(static fn (string $metric, int $value): array => [$metric, (string) $value], array_keys($totals), array_values($totals)),
        );

        if (!$execute) {
            $io->note('Dry-run only. No canonical transactions were deleted.');
        }

        return $totals['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawRows(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $companyId, ?string $shopRef, int $limit): array
    {
        $conditions = [
            'source = :source',
            'resource_type = :resourceType',
            'normalization_status = :status',
            "external_id ~ '^accrual-by-day:[0-9]{4}-[0-9]{2}-[0-9]{2}:[0-9]{4}-[0-9]{2}-[0-9]{2}$'",
            "split_part(external_id, ':', 3)::date >= :fromDate",
            "split_part(external_id, ':', 2)::date <= :toDate",
        ];
        $params = [
            'source' => IngestSource::OZON->value,
            'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
            'status' => RawNormalizationStatus::DONE->value,
            'fromDate' => $from->format('Y-m-d'),
            'toDate' => $to->format('Y-m-d'),
        ];

        if (null !== $companyId) {
            $conditions[] = 'company_id = :companyId';
            $params['companyId'] = $companyId;
        }

        if (null !== $shopRef) {
            $conditions[] = 'shop_ref = :shopRef';
            $params['shopRef'] = $shopRef;
        }

        return $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT id, company_id, shop_ref, external_id, fetched_at
                 FROM ingest_raw_records
                 WHERE %s
                 ORDER BY fetched_at DESC, created_at DESC, id DESC
                 LIMIT %d',
                implode(' AND ', $conditions),
                $limit,
            ),
            $params,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $groupedRows
     * @param list<array<string, mixed>> $rows
     */
    private function collectGroupedRows(array &$groupedRows, IngestRawRecord $rawRecord, array $rows): void
    {
        foreach ($rows as $row) {
            $key = implode("\0", [
                $rawRecord->getCompanyId(),
                $rawRecord->getShopRef(),
                (string) $row['date'],
                (string) $row['stale_raw_external_id'],
                (string) $row['stale_raw_fetched_at'],
                (string) $row['type'],
                (string) $row['direction'],
            ]);

            $groupedRows[$key] ??= [
                'companyId' => $rawRecord->getCompanyId(),
                'shopRef' => $rawRecord->getShopRef(),
                'date' => (string) $row['date'],
                'staleRaw' => (string) $row['stale_raw_external_id'],
                'staleFetchedAt' => (string) $row['stale_raw_fetched_at'],
                'type' => (string) $row['type'],
                'direction' => (string) $row['direction'],
                'count' => 0,
                'amountMinor' => 0,
            ];
            ++$groupedRows[$key]['count'];
            $groupedRows[$key]['amountMinor'] += (int) $row['amount_minor'];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $groupedRows
     */
    private function printGroupedRows(SymfonyStyle $io, array $groupedRows): void
    {
        if ([] === $groupedRows) {
            $io->writeln('No stale canonical transactions found.');

            return;
        }

        $rows = array_values($groupedRows);
        usort($rows, static fn (array $a, array $b): int => [$a['companyId'], $a['shopRef'], $a['date'], $a['staleFetchedAt'], $a['type'], $a['direction']] <=> [$b['companyId'], $b['shopRef'], $b['date'], $b['staleFetchedAt'], $b['type'], $b['direction']]);

        $io->table(
            ['companyId', 'shopRef', 'date', 'staleRaw', 'staleFetchedAt', 'type', 'direction', 'count', 'amountRub'],
            array_map(static fn (array $row): array => [
                $row['companyId'],
                $row['shopRef'],
                $row['date'],
                $row['staleRaw'],
                $row['staleFetchedAt'],
                $row['type'],
                $row['direction'],
                (string) $row['count'],
                number_format($row['amountMinor'] / 100, 2, '.', ''),
            ], $rows),
        );
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: int|null}
     */
    private function dateWindow(InputInterface $input): array
    {
        $from = $this->optionalDateOption($input, 'from');
        $to = $this->optionalDateOption($input, 'to');
        if ((null === $from) !== (null === $to)) {
            throw new \InvalidArgumentException('Options --from and --to must be provided together.');
        }

        if (null !== $from && null !== $to) {
            if ($from > $to) {
                throw new \InvalidArgumentException('--from cannot be later than --to.');
            }

            return [$from, $to, null];
        }

        $daysBack = $this->intOption($input, 'days-back', 1, 365);
        $today = \DateTimeImmutable::createFromInterface(
            $this->clock->now()->setTimezone(new \DateTimeZone(self::BUSINESS_TIMEZONE)),
        )->setTime(0, 0);

        return [$today->modify(sprintf('-%d days', $daysBack)), $today->modify('-1 day'), $daysBack];
    }

    private function optionalDateOption(InputInterface $input, string $name): ?\DateTimeImmutable
    {
        $value = $this->optionalStringOption($input, $name);
        if (null === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(self::BUSINESS_TIMEZONE));
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('--%s must be a valid YYYY-MM-DD date.', $name));
        }

        return $date;
    }

    private function optionalUuidOption(InputInterface $input, string $name): ?string
    {
        $value = $this->optionalStringOption($input, $name);
        if (null === $value) {
            return null;
        }

        Assert::uuid($value, sprintf('Invalid --%s UUID.', $name));

        return $value;
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

    private function mode(InputInterface $input): bool
    {
        $modes = array_values(array_filter([
            (bool) $input->getOption('dry-run') ? 'dry-run' : null,
            (bool) $input->getOption('execute') ? 'execute' : null,
        ]));

        if (1 !== count($modes)) {
            throw new \InvalidArgumentException('Choose exactly one action: --dry-run or --execute.');
        }

        return 'execute' === $modes[0];
    }
}
