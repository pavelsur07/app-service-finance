<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayMapper;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:refresh-category-metadata',
    description: 'Refreshes Ozon accrual category metadata on already normalized canonical transactions.',
)]
final class OzonAccrualRefreshCategoryMetadataCommand extends Command
{
    private const CATEGORY_SOURCE_DATA_KEYS = [
        '_ozon_category_code',
        '_ozon_category_label',
        '_ozon_category_group',
        '_ozon_category_parent',
        '_ozon_category_sort_order',
        '_ozon_category_known',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly IngestRawRecordRepository $rawRecordRepository,
        private readonly FinancialTransactionRepository $financialTransactionRepository,
        private readonly RawStorageFacade $rawStorageFacade,
        private readonly OzonAccrualByDayMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start accrual date YYYY-MM-DD.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End accrual date YYYY-MM-DD.')
            ->addOption('shop-ref', null, InputOption::VALUE_REQUIRED, 'Optional shop reference.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Raw records to process, 1..500.', 100)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show selected records and planned metadata updates without writing.')
            ->addOption('execute-inline', null, InputOption::VALUE_NONE, 'Refresh metadata synchronously in this process.');
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

        $io->title('Ozon accrual category metadata refresh');
        $this->printRawRecords($io, $rawRecords);

        if ([] === $rawRecords) {
            return Command::SUCCESS;
        }

        $dryRun = 'dry-run' === $mode;
        $resultRows = $this->refresh($companyId, $rawRecords, $dryRun);
        $this->printActionResult($io, $resultRows);

        $failed = array_values(array_filter($resultRows, static fn (array $row): bool => 'error' === $row['status']));
        if ([] !== $failed) {
            $io->warning(sprintf('Metadata refresh finished with %d failed raw records.', count($failed)));

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->note('Dry-run only. No canonical transactions were changed.');

            return Command::SUCCESS;
        }

        $updated = array_sum(array_map(static fn (array $row): int => (int) $row['updated'], $resultRows));
        $io->success(sprintf('Refreshed Ozon category metadata on %d canonical transactions.', $updated));

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
        $externalWindowFrom = "substring(r.external_id from '^accrual-by-day:([0-9]{4}-[0-9]{2}-[0-9]{2}):[0-9]{4}-[0-9]{2}-[0-9]{2}$')::date";
        $externalWindowTo = "substring(r.external_id from '^accrual-by-day:[0-9]{4}-[0-9]{2}-[0-9]{2}:([0-9]{4}-[0-9]{2}-[0-9]{2})$')::date";
        $windowFrom = sprintf('COALESCE(j.window_from, %s, DATE(r.fetched_at))', $externalWindowFrom);
        $windowTo = sprintf('COALESCE(j.window_to, j.window_from, %s, %s, DATE(r.fetched_at))', $externalWindowTo, $externalWindowFrom);
        $conditions = [
            'r.company_id = :companyId',
            'r.source = :source',
            'r.resource_type = :resourceType',
            'r.normalization_status = :status',
            sprintf('%s <= :toDate', $windowFrom),
            sprintf('%s >= :fromDate', $windowTo),
        ];
        $params = [
            'companyId' => $companyId,
            'source' => IngestSource::OZON->value,
            'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
            'status' => RawNormalizationStatus::DONE->value,
            'fromDate' => $from->format('Y-m-d'),
            'toDate' => $to->format('Y-m-d'),
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
                        TO_CHAR(%s, \'YYYY-MM-DD\') AS window_from,
                        TO_CHAR(%s, \'YYYY-MM-DD\') AS window_to
                 FROM ingest_raw_records r
                 LEFT JOIN ingest_sync_jobs j ON j.id::text = r.sync_job_id AND j.company_id = r.company_id
                 WHERE %s
                 ORDER BY %s ASC, %s ASC, r.fetched_at ASC, r.created_at ASC
                 LIMIT %d',
                $windowFrom,
                $windowTo,
                implode(' AND ', $conditions),
                $windowFrom,
                $windowTo,
                $limit,
            ),
            $params,
        );
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     *
     * @return list<array<string, string|int>>
     */
    private function refresh(string $companyId, array $rawRecords, bool $dryRun): array
    {
        $resultRows = [];

        foreach ($rawRecords as $row) {
            $rawRecordId = (string) $row['id'];
            $connection = $this->entityManager->getConnection();

            if (!$dryRun) {
                $connection->beginTransaction();
            }

            try {
                $rawRecord = $this->rawRecordRepository->findByIdAndCompany($rawRecordId, $companyId);
                if (null === $rawRecord || RawNormalizationStatus::DONE !== $rawRecord->getNormalizationStatus()) {
                    throw new \RuntimeException('Done Ozon accrual raw record was not found.');
                }

                /** @var list<array<string, mixed>> $rows */
                $rows = array_values(iterator_to_array($this->rawStorageFacade->read($rawRecord->getId(), $companyId), false));
                $mappedTransactions = $this->mapper->map($rawRecord, $rows);
                $existingByKey = $this->existingTransactionsByNaturalKey($companyId, $rawRecord);

                $scanned = 0;
                $matched = 0;
                $updated = 0;
                $unchanged = 0;
                $missing = 0;

                foreach ($mappedTransactions as $mappedTransaction) {
                    ++$scanned;
                    $transaction = $existingByKey[$this->naturalKey($mappedTransaction)] ?? null;
                    if (!$transaction instanceof FinancialTransaction) {
                        $transaction = $this->financialTransactionRepository->findByNaturalKey(
                            $companyId,
                            IngestSource::OZON,
                            $mappedTransaction->externalId,
                            $mappedTransaction->type,
                        );
                    }

                    if (!$transaction instanceof FinancialTransaction) {
                        ++$missing;
                        continue;
                    }

                    ++$matched;
                    if (!$this->metadataDiffers($transaction, $mappedTransaction)) {
                        ++$unchanged;
                        continue;
                    }

                    ++$updated;
                    if (!$dryRun) {
                        $transaction->replaceSourceDataFields(
                            $mappedTransaction->sourceData,
                            self::CATEGORY_SOURCE_DATA_KEYS,
                            $mappedTransaction->description,
                        );
                    }
                }

                if (!$dryRun) {
                    $this->entityManager->flush();
                    $connection->commit();
                }

                $resultRows[] = [
                    'rawId' => $rawRecordId,
                    'status' => $dryRun ? 'dry-run' : 'done',
                    'scanned' => $scanned,
                    'matched' => $matched,
                    'updated' => $updated,
                    'unchanged' => $unchanged,
                    'missing' => $missing,
                ];
            } catch (\Throwable $exception) {
                if (!$dryRun && $connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                $resultRows[] = [
                    'rawId' => $rawRecordId,
                    'status' => 'error',
                    'scanned' => 0,
                    'matched' => 0,
                    'updated' => 0,
                    'unchanged' => 0,
                    'missing' => 0,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $resultRows;
    }

    /**
     * @return array<string, FinancialTransaction>
     */
    private function existingTransactionsByNaturalKey(string $companyId, IngestRawRecord $rawRecord): array
    {
        $transactions = [];
        foreach ($this->financialTransactionRepository->findByRawRecordId($companyId, $rawRecord->getId()) as $transaction) {
            $transactions[sprintf('%s:%s', $transaction->getExternalId(), $transaction->getType()->value)] = $transaction;
        }

        return $transactions;
    }

    private function naturalKey(MappedTransaction $transaction): string
    {
        return sprintf('%s:%s', $transaction->externalId, $transaction->type->value);
    }

    private function metadataDiffers(FinancialTransaction $transaction, MappedTransaction $mappedTransaction): bool
    {
        $existing = $transaction->getSourceData();
        foreach (self::CATEGORY_SOURCE_DATA_KEYS as $key) {
            if (!array_key_exists($key, $mappedTransaction->sourceData)) {
                continue;
            }

            if (!array_key_exists($key, $existing) || $existing[$key] !== $mappedTransaction->sourceData[$key]) {
                return true;
            }
        }

        return null !== $mappedTransaction->description && $transaction->getDescription() !== $mappedTransaction->description;
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     */
    private function printRawRecords(SymfonyStyle $io, array $rawRecords): void
    {
        $io->section('Selected raw records');
        if ([] === $rawRecords) {
            $io->writeln('No done Ozon accrual by-day raw records found for the selected period.');

            return;
        }

        $io->table(
            ['windowFrom', 'windowTo', 'rawId', 'externalId', 'shopRef', 'status', 'bytes', 'fetchedAt'],
            array_map(static fn (array $row): array => [
                (string) ($row['window_from'] ?? ''),
                (string) ($row['window_to'] ?? ''),
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
        $io->section('Metadata refresh result');
        if ([] === $resultRows) {
            $io->writeln('No records were processed.');

            return;
        }

        $io->table(
            ['rawId', 'status', 'scanned', 'matched', 'updated', 'unchanged', 'missing', 'error'],
            array_map(static fn (array $row): array => [
                (string) $row['rawId'],
                (string) $row['status'],
                (string) $row['scanned'],
                (string) $row['matched'],
                (string) $row['updated'],
                (string) $row['unchanged'],
                (string) $row['missing'],
                (string) ($row['error'] ?? ''),
            ], $resultRows),
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
            (bool) $input->getOption('execute-inline') ? 'execute-inline' : null,
        ]));

        if (1 !== count($modes)) {
            throw new \InvalidArgumentException('Choose exactly one action: --dry-run or --execute-inline.');
        }

        return $modes[0];
    }
}
