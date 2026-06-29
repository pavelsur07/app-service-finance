<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\NormalizeRawRecordAction;
use App\Ingestion\Application\Action\RecordNormalizationIssueAction;
use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Application\Command\RecordNormalizationIssueCommand;
use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayPreviewMapper;
use App\Ingestion\Application\Source\Ozon\OzonAccrualPreviewTransaction;
use App\Ingestion\Application\Source\Ozon\OzonListingResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Ingestion\Repository\NormalizationIssueRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:refresh-financial-verification',
    description: 'Replays stored Ozon accrual raw data and repairs normalized FinancialTransaction enrichment for verification reports.',
)]
final class OzonAccrualRefreshFinancialVerificationCommand extends Command
{
    use LockableTrait;
    use OzonAccrualCommandHelperTrait;

    private const BUSINESS_TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly Connection $connection,
        private readonly IngestRawRecordRepository $rawRecordRepository,
        private readonly NormalizationIssueRepository $normalizationIssueRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly NormalizeRawRecordAction $normalizeRawRecordAction,
        private readonly RecordNormalizationIssueAction $recordNormalizationIssueAction,
        private readonly OzonListingResolver $listingResolver,
        private readonly RawStorageFacade $rawStorageFacade,
        private readonly OzonAccrualByDayPreviewMapper $previewMapper,
        private readonly FinancialTransactionRepository $transactionRepository,
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
            ->addOption('raw-limit', null, InputOption::VALUE_REQUIRED, 'Stored raw records to replay, 1..500.', 100)
            ->addOption('relink-limit', null, InputOption::VALUE_REQUIRED, 'Unlinked transactions per enrichment batch, 1..5000.', 5000)
            ->addOption('max-relink-batches', null, InputOption::VALUE_REQUIRED, 'Maximum enrichment batches per run, 1..50.', 20)
            ->addOption('include-done', null, InputOption::VALUE_NONE, 'Also replay already-normalized raw records. Use for manual backfills after mapper changes.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Inspect selected raw records and current enrichment gaps without writing.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Replay selected raw records and persist enrichment repairs.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('<comment>Ozon accrual financial verification refresh is already running.</comment>');

            return Command::SUCCESS;
        }

        try {
            return $this->runRefresh($input, new SymfonyStyle($input, $output));
        } finally {
            $this->release();
        }
    }

    private function runRefresh(InputInterface $input, SymfonyStyle $io): int
    {
        try {
            [$from, $to, $daysBack] = $this->dateWindow($input);
            $companyId = $this->optionalUuidOption($input, 'company-id');
            $shopRef = $this->optionalStringOption($input, 'shop-ref');
            $rawLimit = $this->intOption($input, 'raw-limit', 1, 500);
            $relinkLimit = $this->intOption($input, 'relink-limit', 1, 5000);
            $maxRelinkBatches = $this->intOption($input, 'max-relink-batches', 1, 50);
            $includeDone = (bool) $input->getOption('include-done');
            $execute = $this->mode($input);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $rawRecords = $this->rawRecords($companyId, $shopRef, $from, $to, $rawLimit, $includeDone);

        $io->title('Ozon accrual financial verification refresh');
        $io->table(
            ['setting', 'value'],
            [
                ['mode', $execute ? 'execute' : 'dry-run'],
                ['from', $from->format('Y-m-d')],
                ['to', $to->format('Y-m-d')],
                ['daysBack', null === $daysBack ? 'custom' : (string) $daysBack],
                ['companyId', $companyId ?? 'all'],
                ['shopRef', $shopRef ?? 'all'],
                ['rawLimit', (string) $rawLimit],
                ['includeDone', $includeDone ? 'yes' : 'no'],
                ['relinkLimit', (string) $relinkLimit],
                ['maxRelinkBatches', (string) $maxRelinkBatches],
            ],
        );
        $this->printRawRecords($io, $rawRecords);

        $normalization = $execute
            ? $this->replayRawRecords($rawRecords)
            : ['selected' => count($rawRecords), 'changed' => 0, 'done' => 0, 'failed' => 0];
        $io->section('Raw replay');
        $this->printMetrics($io, $normalization);

        $enrichment = $this->repairListingEnrichment($companyId, $shopRef, $from, $to, $relinkLimit, $maxRelinkBatches, $execute);
        $io->section('Listing enrichment repair');
        $this->printMetrics($io, $enrichment);

        if (!$execute) {
            $io->note('Dry-run only. Use --execute to replay raw records and persist enrichment repairs.');
        }

        return 0 === $normalization['failed'] ? Command::SUCCESS : Command::FAILURE;
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

    /**
     * @return list<array<string, mixed>>
     */
    private function rawRecords(
        ?string $companyId,
        ?string $shopRef,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
        bool $includeDone,
    ): array {
        $externalWindowFrom = "substring(r.external_id from '^accrual-by-day:([0-9]{4}-[0-9]{2}-[0-9]{2}):[0-9]{4}-[0-9]{2}-[0-9]{2}$')::date";
        $externalWindowTo = "substring(r.external_id from '^accrual-by-day:[0-9]{4}-[0-9]{2}-[0-9]{2}:([0-9]{4}-[0-9]{2}-[0-9]{2})$')::date";
        $windowFrom = sprintf('COALESCE(j.window_from, %s, DATE(r.fetched_at))', $externalWindowFrom);
        $windowTo = sprintf('COALESCE(j.window_to, j.window_from, %s, %s, DATE(r.fetched_at))', $externalWindowTo, $externalWindowFrom);
        $statuses = [
            RawNormalizationStatus::PENDING->value,
            RawNormalizationStatus::SKIPPED->value,
            RawNormalizationStatus::FAILED->value,
        ];
        if ($includeDone) {
            $statuses[] = RawNormalizationStatus::DONE->value;
        }

        $conditions = [
            'r.source = :source',
            'r.resource_type = :resourceType',
            'r.normalization_status IN (:statuses)',
            sprintf('%s <= :toDate', $windowFrom),
            sprintf('%s >= :fromDate', $windowTo),
        ];
        $params = [
            'source' => IngestSource::OZON->value,
            'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
            'statuses' => $statuses,
            'fromDate' => $from->format('Y-m-d'),
            'toDate' => $to->format('Y-m-d'),
        ];
        $types = ['statuses' => ArrayParameterType::STRING];

        if (null !== $companyId) {
            $conditions[] = 'r.company_id = :companyId';
            $params['companyId'] = $companyId;
        }
        if (null !== $shopRef && '' !== $shopRef) {
            $conditions[] = 'r.shop_ref = :shopRef';
            $params['shopRef'] = $shopRef;
        }

        return $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT r.company_id,
                        r.id,
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
                 ORDER BY CASE r.normalization_status
                              WHEN \'pending\' THEN 0
                              WHEN \'failed\' THEN 1
                              WHEN \'skipped\' THEN 2
                              ELSE 3
                          END ASC,
                          %s ASC,
                          %s ASC,
                          r.fetched_at ASC,
                          r.created_at ASC
                 LIMIT %d',
                $windowFrom,
                $windowTo,
                implode(' AND ', $conditions),
                $windowFrom,
                $windowTo,
                $limit,
            ),
            $params,
            $types,
        );
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     *
     * @return array{selected: int, changed: int, done: int, failed: int}
     */
    private function replayRawRecords(array $rawRecords): array
    {
        $metrics = ['selected' => count($rawRecords), 'changed' => 0, 'done' => 0, 'failed' => 0];

        foreach ($rawRecords as $row) {
            $companyId = (string) $row['company_id'];
            $rawRecordId = (string) $row['id'];
            $record = $this->rawRecordRepository->findByIdAndCompany($rawRecordId, $companyId);
            if (!$record instanceof IngestRawRecord || !$this->canReplay($record)) {
                continue;
            }

            $this->prepareRecordForReplay($record);
            $this->resolveOpenIssues($companyId, $rawRecordId);
            $this->entityManager->flush();
            ++$metrics['changed'];

            try {
                ($this->normalizeRawRecordAction)(new NormalizeRawRecordCommand($rawRecordId, $companyId));
            } catch (\Throwable $exception) {
                $this->markInlineFailure($record, $exception);
            }

            if (RawNormalizationStatus::DONE === $this->normalizationStatus($companyId, $rawRecordId)) {
                ++$metrics['done'];
            } else {
                ++$metrics['failed'];
            }
        }

        return $metrics;
    }

    private function canReplay(IngestRawRecord $record): bool
    {
        return in_array(
            $record->getNormalizationStatus(),
            [
                RawNormalizationStatus::PENDING,
                RawNormalizationStatus::DONE,
                RawNormalizationStatus::SKIPPED,
                RawNormalizationStatus::FAILED,
            ],
            true,
        );
    }

    private function prepareRecordForReplay(IngestRawRecord $record): void
    {
        if (RawNormalizationStatus::PENDING === $record->getNormalizationStatus()) {
            return;
        }

        if (RawNormalizationStatus::DONE === $record->getNormalizationStatus()) {
            $record->markNormalizationFailed();
        }

        $record->markNormalizationPending();
    }

    private function resolveOpenIssues(string $companyId, string $rawRecordId): void
    {
        foreach ($this->normalizationIssueRepository->findOpenByRawRecord($companyId, $rawRecordId) as $issue) {
            $issue->markResolved();
        }
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
                'source' => 'ozon_accrual_refresh_financial_verification',
            ],
        ));
        $this->entityManager->flush();
    }

    private function normalizationStatus(string $companyId, string $rawRecordId): RawNormalizationStatus
    {
        $status = (string) $this->connection->fetchOne(
            'SELECT normalization_status FROM ingest_raw_records WHERE company_id = :companyId AND id = :rawRecordId',
            ['companyId' => $companyId, 'rawRecordId' => $rawRecordId],
        );

        return RawNormalizationStatus::from($status);
    }

    /**
     * @return array{batches: int, selected: int, resolved: int, updated: int, wouldCreateListings: int, unresolved: int}
     */
    private function repairListingEnrichment(
        ?string $companyId,
        ?string $shopRef,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
        int $maxBatches,
        bool $execute,
    ): array {
        $metrics = ['batches' => 0, 'selected' => 0, 'resolved' => 0, 'updated' => 0, 'wouldCreateListings' => 0, 'unresolved' => 0];

        for ($batch = 0; $batch < $maxBatches; ++$batch) {
            $rows = $this->selectUnlinkedRows($companyId, $shopRef, $from, $to, $limit);
            if ([] === $rows) {
                break;
            }

            ++$metrics['batches'];
            $metrics['selected'] += count($rows);
            $result = $this->repairListingRows($rows, $execute);
            $metrics['resolved'] += $result['resolved'];
            $metrics['updated'] += $result['updated'];
            $metrics['wouldCreateListings'] += $result['wouldCreateListings'];
            $metrics['unresolved'] += count($rows) - $result['resolved'];

            if (!$execute || 0 === $result['updated']) {
                break;
            }
        }

        return $metrics;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectUnlinkedRows(
        ?string $companyId,
        ?string $shopRef,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
    ): array {
        $where = [
            'source = :source',
            'listing_id IS NULL',
            "(external_id LIKE :externalIdPrefix OR source_data->>'_ingestion_resource' = :resourceType)",
            'occurred_at >= :from',
            'occurred_at < :toExclusive',
        ];
        $params = [
            'source' => IngestSource::OZON->value,
            'externalIdPrefix' => 'ozon:accrual-by-day:%',
            'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
            'from' => $from->setTime(0, 0)->format('Y-m-d H:i:s'),
            'toExclusive' => $to->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s'),
        ];

        if (null !== $companyId) {
            $where[] = 'company_id = :companyId';
            $params['companyId'] = $companyId;
        }
        if (null !== $shopRef && '' !== $shopRef) {
            $where[] = 'shop_ref = :shopRef';
            $params['shopRef'] = $shopRef;
        }

        return $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT id, company_id, external_id, occurred_at, source_data, raw_record_id
                 FROM ingest_financial_transactions
                 WHERE %s
                 ORDER BY occurred_at ASC, id ASC
                 LIMIT %d',
                implode(' AND ', $where),
                $limit,
            ),
            $params,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{resolved: int, updated: int, wouldCreateListings: int}
     */
    private function repairListingRows(array $rows, bool $execute): array
    {
        $recoveredSourceData = $this->recoverListingSourceData($rows);
        $sourceDataByCompany = [];
        foreach ($rows as $row) {
            $sourceData = $this->decodeSourceData($row['source_data'] ?? null);
            if (!$this->hasMarketplaceSkuContext($sourceData)) {
                $sourceData = array_merge(
                    $sourceData,
                    $recoveredSourceData[(string) $row['company_id']][(string) $row['raw_record_id']][(string) $row['external_id']] ?? [],
                );
            }

            $sourceDataByCompany[(string) $row['company_id']][(string) $row['id']] = $sourceData;
        }

        $resolutions = [];
        $wouldCreateById = [];
        foreach ($sourceDataByCompany as $rowCompanyId => $sourceDataRows) {
            if ($execute) {
                $resolutions += $this->listingResolver->resolveMany($rowCompanyId, $sourceDataRows);
                continue;
            }

            foreach ($this->listingResolver->previewMany($rowCompanyId, $sourceDataRows) as $transactionId => $preview) {
                $resolutions[$transactionId] = $preview->resolution;
                $wouldCreateById[$transactionId] = $preview->wouldCreate;
            }
        }

        $resolved = 0;
        $updated = 0;
        $wouldCreateListings = 0;
        $transactionsById = $execute ? $this->fetchTransactionsToUpdate($rows, $resolutions) : [];

        foreach ($rows as $row) {
            $transactionId = (string) $row['id'];
            $resolution = $resolutions[$transactionId] ?? null;

            if (null !== $resolution?->listingId && null !== $resolution->listingSku) {
                ++$resolved;

                if ($execute) {
                    $transaction = $transactionsById[$transactionId] ?? null;
                    if ($transaction instanceof FinancialTransaction && null === $transaction->getListingId()) {
                        $transaction->setListing($resolution->listingId, $resolution->listingSku);
                        ++$updated;
                    }
                }
            } elseif (!$execute && true === ($wouldCreateById[$transactionId] ?? false) && null !== $resolution?->listingSku) {
                ++$resolved;
                ++$wouldCreateListings;
            }
        }

        if ($execute) {
            $this->entityManager->flush();
        }

        return ['resolved' => $resolved, 'updated' => $updated, 'wouldCreateListings' => $wouldCreateListings];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array<string, array<string, array<string, mixed>>>>
     */
    private function recoverListingSourceData(array $rows): array
    {
        $rawRecordIdsByCompany = [];
        foreach ($rows as $row) {
            $sourceData = $this->decodeSourceData($row['source_data'] ?? null);
            if ($this->hasMarketplaceSkuContext($sourceData)) {
                continue;
            }

            $companyId = $this->stringValue($row['company_id'] ?? null);
            $rawRecordId = $this->stringValue($row['raw_record_id'] ?? null);
            if (null === $companyId || null === $rawRecordId) {
                continue;
            }

            $rawRecordIdsByCompany[$companyId][$rawRecordId] = $rawRecordId;
        }

        $recovered = [];
        foreach ($rawRecordIdsByCompany as $companyId => $rawRecordIds) {
            foreach ($rawRecordIds as $rawRecordId) {
                try {
                    $previewRows = $this->previewMapper->preview($companyId, $this->rawStorageFacade->read($rawRecordId, $companyId), includeSaleRefund: true);
                } catch (\Throwable) {
                    continue;
                }

                foreach ($previewRows as $previewRow) {
                    $sourceData = $this->listingSourceData($previewRow);
                    if ([] !== $sourceData) {
                        $recovered[$companyId][$rawRecordId][$previewRow->sourceKey] = $sourceData;
                    }
                }
            }
        }

        return $recovered;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, \App\Ingestion\Application\DTO\ListingResolution|null> $resolutions
     *
     * @return array<string, FinancialTransaction>
     */
    private function fetchTransactionsToUpdate(array $rows, array $resolutions): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $transactionId = (string) $row['id'];
            $resolution = $resolutions[$transactionId] ?? null;
            if (null !== $resolution?->listingId && null !== $resolution->listingSku) {
                $ids[$transactionId] = $transactionId;
            }
        }

        if ([] === $ids) {
            return [];
        }

        $transactionsById = [];
        foreach ($this->transactionRepository->findBy(['id' => array_values($ids)]) as $transaction) {
            if ($transaction instanceof FinancialTransaction) {
                $transactionsById[$transaction->getId()] = $transaction;
            }
        }

        return $transactionsById;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSourceData(mixed $sourceData): array
    {
        if (is_array($sourceData)) {
            return $sourceData;
        }

        if (!is_string($sourceData) || '' === trim($sourceData)) {
            return [];
        }

        $decoded = json_decode($sourceData, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $sourceData
     */
    private function hasMarketplaceSkuContext(array $sourceData): bool
    {
        if (null !== $this->stringValue($sourceData['sku'] ?? $sourceData['marketplace_sku'] ?? $sourceData['marketplaceSku'] ?? null)) {
            return true;
        }

        $item = $sourceData['item'] ?? null;
        if (is_array($item) && null !== $this->stringValue($item['sku'] ?? null)) {
            return true;
        }

        $items = $sourceData['items'] ?? null;
        if (is_array($items) && isset($items[0]) && is_array($items[0])) {
            return null !== $this->stringValue($items[0]['sku'] ?? null);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function listingSourceData(OzonAccrualPreviewTransaction $row): array
    {
        $sourceData = [];
        if (null !== $row->marketplaceSku) {
            $sourceData['sku'] = $row->marketplaceSku;
        }
        if (null !== $row->supplierSku) {
            $sourceData['offer_id'] = $row->supplierSku;
        }
        if (null !== $row->listingName) {
            $sourceData['name'] = $row->listingName;
        }

        return $sourceData;
    }

    private function stringValue(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     */
    private function printRawRecords(SymfonyStyle $io, array $rawRecords): void
    {
        $io->section('Selected raw records');
        if ([] === $rawRecords) {
            $io->writeln('No stored Ozon accrual by-day raw records found for the selected period.');

            return;
        }

        $io->table(
            ['companyId', 'windowFrom', 'windowTo', 'rawId', 'externalId', 'shopRef', 'status', 'bytes', 'fetchedAt'],
            array_map(static fn (array $row): array => [
                (string) $row['company_id'],
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

    private function mode(InputInterface $input): bool
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $execute = (bool) $input->getOption('execute');

        if ($dryRun === $execute) {
            throw new \InvalidArgumentException('Choose exactly one action: --dry-run or --execute.');
        }

        return $execute;
    }
}
