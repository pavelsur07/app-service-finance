<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\NormalizeRawRecordAction;
use App\Ingestion\Application\Action\RecordNormalizationIssueAction;
use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Application\Command\RecordNormalizationIssueCommand;
use App\Ingestion\Application\Service\OzonAccrualListingRelinker;
use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayPreviewMapper;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Infrastructure\Query\OzonAccrualProjectionHealthQuery;
use App\Ingestion\Message\NormalizeRawRecordMessage;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Ingestion\Repository\NormalizationIssueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:reconcile-financial-projection',
    description: 'Reconciles Ozon accrual raw snapshots with normalized financial transactions and repairs projection enrichment.',
)]
final class OzonAccrualReconcileFinancialProjectionCommand extends Command
{
    use LockableTrait;

    private const BUSINESS_TIMEZONE = 'Europe/Moscow';
    private const MAX_RAW_PROJECTION_CANDIDATES = 500;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly OzonAccrualProjectionHealthQuery $healthQuery,
        private readonly IngestRawRecordRepository $rawRecordRepository,
        private readonly NormalizationIssueRepository $normalizationIssueRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly NormalizeRawRecordAction $normalizeRawRecordAction,
        private readonly RecordNormalizationIssueAction $recordNormalizationIssueAction,
        private readonly OzonAccrualListingRelinker $listingRelinker,
        private readonly RawStorageFacade $rawStorageFacade,
        private readonly OzonAccrualByDayPreviewMapper $previewMapper,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days-back', null, InputOption::VALUE_REQUIRED, 'Rolling window size. Used when --from/--to are omitted.', 30)
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Optional start accrual date YYYY-MM-DD. Must be paired with --to.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Optional end accrual date YYYY-MM-DD. Must be paired with --from.')
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Optional company UUID filter.')
            ->addOption('shop-ref', null, InputOption::VALUE_REQUIRED, 'Optional shop reference filter.')
            ->addOption('raw-limit', null, InputOption::VALUE_REQUIRED, 'Maximum stale raw records to act on, 1..500.', 100)
            ->addOption('relink-limit', null, InputOption::VALUE_REQUIRED, 'Maximum unlinked transactions per relink batch, 1..5000.', 5000)
            ->addOption('max-relink-batches', null, InputOption::VALUE_REQUIRED, 'Maximum relink batches per run, 1..50.', 20)
            ->addOption('dispatch-normalization', null, InputOption::VALUE_NONE, 'Reset stale raw records and dispatch async normalization messages.')
            ->addOption('execute-inline-normalization', null, InputOption::VALUE_NONE, 'Reset stale raw records and normalize them synchronously.')
            ->addOption('repair-enrichment-only', null, InputOption::VALUE_NONE, 'Skip raw normalization actions and only repair listing enrichment.')
            ->addOption('summary-only', null, InputOption::VALUE_NONE, 'Print only summary tables.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print machine-readable JSON result.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Inspect stale projection and planned enrichment repair without writing.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Persist requested projection repair actions.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('<comment>Ozon accrual financial projection reconciliation is already running.</comment>');

            return Command::SUCCESS;
        }

        try {
            return $this->runReconciliation($input, new SymfonyStyle($input, $output));
        } finally {
            $this->release();
        }
    }

    private function runReconciliation(InputInterface $input, SymfonyStyle $io): int
    {
        try {
            [$from, $to, $daysBack] = $this->dateWindow($input);
            $companyId = $this->optionalUuidOption($input, 'company-id');
            $shopRef = $this->optionalStringOption($input, 'shop-ref');
            $rawLimit = $this->intOption($input, 'raw-limit', 1, 500);
            $relinkLimit = $this->intOption($input, 'relink-limit', 1, 5000);
            $maxRelinkBatches = $this->intOption($input, 'max-relink-batches', 1, 50);
            $execute = $this->mode($input);
            $normalizationMode = $this->normalizationMode($input, $execute);
            $repairEnrichmentOnly = (bool) $input->getOption('repair-enrichment-only');
            if ($repairEnrichmentOnly && null !== $normalizationMode) {
                throw new \InvalidArgumentException('--repair-enrichment-only cannot be combined with normalization actions.');
            }
            $summaryOnly = (bool) $input->getOption('summary-only');
            $json = (bool) $input->getOption('json');
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $rawRows = $this->resolveNaturalProjectionCoverage(
            $this->healthQuery->rawProjectionRows($from, $to, $companyId, $shopRef, self::MAX_RAW_PROJECTION_CANDIDATES, problemsOnly: true),
        );
        $rawRows = array_values(array_filter($rawRows, fn (array $row): bool => $this->needsNormalization($row)));
        $rawRows = array_slice($rawRows, 0, $rawLimit);
        $integrityBefore = $this->healthQuery->integritySummary($from, $to, $companyId, $shopRef);
        $staleRows = $rawRows;
        if ($execute && [] !== $staleRows && null === $normalizationMode && !$repairEnrichmentOnly) {
            $io->error('Stale raw records were found. Use --dispatch-normalization or --execute-inline-normalization with --execute.');

            return Command::FAILURE;
        }

        $normalizationResult = [
            'mode' => $repairEnrichmentOnly ? 'repair-enrichment-only' : ($normalizationMode ?? 'none'),
            'selected' => count($staleRows),
            'changed' => 0,
            'failed' => 0,
            'rows' => [],
        ];
        $relinkResult = [
            'batches' => 0,
            'selected' => 0,
            'resolved' => 0,
            'updated' => 0,
            'wouldCreateListings' => 0,
            'unresolved' => 0,
            'finalRecoverable' => 0,
            'rows' => [],
        ];
        $relinkDeferred = false;

        if ($execute && [] !== $staleRows) {
            if ('dispatch' === $normalizationMode) {
                $normalizationResult = $this->dispatchNormalization($staleRows);
                $relinkDeferred = true;
            } elseif ('inline' === $normalizationMode) {
                $normalizationResult = $this->executeInlineNormalization($staleRows);
                $relinkDeferred = ($normalizationResult['failed'] ?? 0) > 0;
            }
        }

        if (!$execute) {
            $preview = $this->listingRelinker->relink(
                companyId: $companyId,
                from: $from,
                to: $to,
                limit: $relinkLimit,
                execute: false,
                shopRef: $shopRef,
                componentFilter: OzonAccrualListingRelinker::COMPONENT_FILTER_LINKABLE,
                includeRows: !$summaryOnly && !$json,
            );
            $relinkResult = array_merge($relinkResult, [
                'batches' => 1,
                'selected' => $preview['selected'],
                'resolved' => $preview['resolved'],
                'updated' => $preview['updated'],
                'wouldCreateListings' => $preview['wouldCreateListings'],
                'unresolved' => $preview['unresolved'],
                'finalRecoverable' => $preview['resolved'],
                'rows' => $preview['rows'],
            ]);
        } elseif (!$relinkDeferred) {
            $relinkResult = $this->executeRelinkBatches($companyId, $shopRef, $from, $to, $relinkLimit, $maxRelinkBatches);
        }

        $integrityAfter = $execute && !$relinkDeferred
            ? $this->healthQuery->integritySummary($from, $to, $companyId, $shopRef)
            : $integrityBefore;

        $payload = [
            'mode' => $execute ? 'execute' : 'dry-run',
            'normalizationMode' => $normalizationMode ?? 'none',
            'repairEnrichmentOnly' => $repairEnrichmentOnly,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'daysBack' => $daysBack,
            'companyId' => $companyId,
            'shopRef' => $shopRef,
            'rawLimit' => $rawLimit,
            'relinkLimit' => $relinkLimit,
            'maxRelinkBatches' => $maxRelinkBatches,
            'rawProblems' => [
                'selected' => count($rawRows),
                'needsNormalization' => count($staleRows),
                'summary' => $this->rawSummary($rawRows),
            ],
            'normalization' => $this->withoutRows($normalizationResult),
            'relink' => $this->withoutRows($relinkResult),
            'relinkDeferred' => $relinkDeferred,
            'integrityBefore' => $integrityBefore,
            'integrityAfter' => $integrityAfter,
        ];

        $this->logger->info('Ozon accrual financial projection reconciliation finished.', $payload);

        if ($json) {
            $io->writeln((string) json_encode($payload, \JSON_THROW_ON_ERROR));

            return $this->exitCode($payload);
        }

        $this->printReport(
            io: $io,
            payload: $payload,
            rawRows: $rawRows,
            normalizationRows: $normalizationResult['rows'],
            relinkRows: $relinkResult['rows'],
            summaryOnly: $summaryOnly,
        );

        return $this->exitCode($payload);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{mode: string, selected: int, changed: int, failed: int, rows: list<array<string, string|int>>}
     */
    private function dispatchNormalization(array $rows): array
    {
        $resultRows = [];
        $changed = 0;
        $failed = 0;
        $messages = [];

        foreach ($rows as $row) {
            $companyId = (string) $row['company_id'];
            $rawRecordId = (string) $row['raw_id'];
            $record = $this->rawRecordRepository->findByIdAndCompany($rawRecordId, $companyId);
            if (!$record instanceof IngestRawRecord) {
                ++$failed;
                $resultRows[] = ['rawId' => $rawRecordId, 'status' => 'missing', 'txCount' => (int) $row['tx_count'], 'openIssues' => (int) $row['open_issues']];
                continue;
            }

            $this->resetRecordToPending($record);
            $this->resolveOpenIssues($companyId, $rawRecordId);
            $messages[] = new NormalizeRawRecordMessage($rawRecordId, $companyId);
            ++$changed;
            $resultRows[] = ['rawId' => $rawRecordId, 'status' => RawNormalizationStatus::PENDING->value, 'txCount' => (int) $row['tx_count'], 'openIssues' => 0];
        }

        $this->entityManager->flush();
        foreach ($messages as $message) {
            $this->messageBus->dispatch($message);
        }

        return [
            'mode' => 'dispatch',
            'selected' => count($rows),
            'changed' => $changed,
            'failed' => $failed,
            'rows' => $resultRows,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{mode: string, selected: int, changed: int, failed: int, rows: list<array<string, string|int>>}
     */
    private function executeInlineNormalization(array $rows): array
    {
        $resultRows = [];
        $changed = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $companyId = (string) $row['company_id'];
            $rawRecordId = (string) $row['raw_id'];
            $record = $this->rawRecordRepository->findByIdAndCompany($rawRecordId, $companyId);
            if (!$record instanceof IngestRawRecord) {
                ++$failed;
                $resultRows[] = ['rawId' => $rawRecordId, 'status' => 'missing', 'txCount' => (int) $row['tx_count'], 'openIssues' => (int) $row['open_issues']];
                continue;
            }

            $this->resetRecordToPending($record);
            $this->resolveOpenIssues($companyId, $rawRecordId);
            $this->entityManager->flush();

            try {
                ($this->normalizeRawRecordAction)(new NormalizeRawRecordCommand($rawRecordId, $companyId));
            } catch (\Throwable $exception) {
                ++$failed;
                if (!$this->entityManager->isOpen()) {
                    $this->logger->error('Ozon accrual inline normalization aborted because the entity manager is closed.', [
                        'companyId' => $companyId,
                        'rawRecordId' => $rawRecordId,
                        'exceptionClass' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                    $resultRows[] = [
                        'rawId' => $rawRecordId,
                        'status' => 'entity-manager-closed',
                        'txCount' => (int) $row['tx_count'],
                        'openIssues' => (int) $row['open_issues'],
                        'error' => $exception->getMessage(),
                    ];
                    break;
                }

                try {
                    $this->markInlineFailure($record, $exception);
                    $resultRows[] = [
                        'rawId' => $rawRecordId,
                        'status' => 'error',
                        'txCount' => (int) $row['tx_count'],
                        'openIssues' => (int) $row['open_issues'],
                        'error' => $exception->getMessage(),
                    ];
                } catch (\Throwable $failureRecordingException) {
                    $this->logger->error('Ozon accrual inline normalization aborted because failure recording failed.', [
                        'companyId' => $companyId,
                        'rawRecordId' => $rawRecordId,
                        'exceptionClass' => $exception::class,
                        'message' => $exception->getMessage(),
                        'failureRecordingExceptionClass' => $failureRecordingException::class,
                        'failureRecordingMessage' => $failureRecordingException->getMessage(),
                    ]);
                    $status = $this->entityManager->isOpen() ? 'failure-recording-error' : 'entity-manager-closed';
                    $resultRows[] = [
                        'rawId' => $rawRecordId,
                        'status' => $status,
                        'txCount' => (int) $row['tx_count'],
                        'openIssues' => (int) $row['open_issues'],
                        'error' => sprintf(
                            '%s; failure recording failed: %s',
                            $exception->getMessage(),
                            $failureRecordingException->getMessage(),
                        ),
                    ];
                    break;
                }

                continue;
            }

            try {
                $status = $this->normalizationStatus($record);
            } catch (\Throwable $exception) {
                $status = 'normalized-status-unavailable';
                $this->logger->warning('Ozon accrual inline normalization status refresh failed after successful action.', [
                    'companyId' => $companyId,
                    'rawRecordId' => $rawRecordId,
                    'exceptionClass' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }

            ++$changed;
            $resultRows[] = ['rawId' => $rawRecordId, 'status' => $status, 'txCount' => -1, 'openIssues' => 0];

            if (!$this->entityManager->isOpen()) {
                break;
            }
        }

        return [
            'mode' => 'inline',
            'selected' => count($rows),
            'changed' => $changed,
            'failed' => $failed,
            'rows' => $resultRows,
        ];
    }

    /**
     * @return array{batches: int, selected: int, resolved: int, updated: int, wouldCreateListings: int, unresolved: int, finalRecoverable: int, rows: list<array<string, string>>}
     */
    private function executeRelinkBatches(
        ?string $companyId,
        ?string $shopRef,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
        int $maxBatches,
    ): array {
        $totals = [
            'batches' => 0,
            'selected' => 0,
            'resolved' => 0,
            'updated' => 0,
            'wouldCreateListings' => 0,
            'unresolved' => 0,
            'finalRecoverable' => 0,
            'rows' => [],
        ];

        for ($batch = 1; $batch <= $maxBatches; ++$batch) {
            $result = $this->listingRelinker->relink(
                companyId: $companyId,
                from: $from,
                to: $to,
                limit: $limit,
                execute: true,
                shopRef: $shopRef,
                componentFilter: OzonAccrualListingRelinker::COMPONENT_FILTER_LINKABLE,
                includeRows: false,
            );

            ++$totals['batches'];
            $totals['selected'] += $result['selected'];
            $totals['resolved'] += $result['resolved'];
            $totals['updated'] += $result['updated'];
            $totals['wouldCreateListings'] += $result['wouldCreateListings'];
            $totals['unresolved'] = $result['unresolved'];

            if (0 === $result['selected'] || 0 === $result['updated']) {
                break;
            }
        }

        $preview = $this->listingRelinker->relink(
            companyId: $companyId,
            from: $from,
            to: $to,
            limit: $limit,
            execute: false,
            shopRef: $shopRef,
            componentFilter: OzonAccrualListingRelinker::COMPONENT_FILTER_LINKABLE,
            includeRows: false,
        );
        $totals['finalRecoverable'] = $preview['resolved'];

        return $totals;
    }

    private function resetRecordToPending(IngestRawRecord $record): void
    {
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
                'source' => 'ozon_accrual_reconcile_financial_projection_inline',
            ],
        ));
        $this->entityManager->flush();
    }

    private function normalizationStatus(IngestRawRecord $record): string
    {
        $this->entityManager->refresh($record);

        return $record->getNormalizationStatus()->value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function needsNormalization(array $row): bool
    {
        return 1 === (int) $row['needs_normalization'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function resolveNaturalProjectionCoverage(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index]['projection_status'] = 'sql-candidate';
            $rows[$index]['expected_tx_count'] = null;
            $rows[$index]['projected_natural_key_count'] = null;
            $rows[$index]['missing_natural_key_count'] = null;

            if (!$this->needsNaturalProjectionCheck($row)) {
                continue;
            }

            $companyId = (string) $row['company_id'];
            $rawRecordId = (string) $row['raw_id'];

            try {
                $expectedSourceKeys = $this->expectedSourceKeys($companyId, $rawRecordId);
                $expectedExternalIds = [];
                foreach ($expectedSourceKeys as $sourceKey => $_) {
                    $expectedExternalIds[] = $sourceKey;
                }

                $projectedSourceKeys = $this->healthQuery->projectedExternalIdSet(
                    companyId: $companyId,
                    shopRef: (string) $row['shop_ref'],
                    externalIds: $expectedExternalIds,
                );
                $missingSourceKeys = array_diff_key($expectedSourceKeys, $projectedSourceKeys);

                $rows[$index]['expected_tx_count'] = count($expectedSourceKeys);
                $rows[$index]['projected_natural_key_count'] = count($projectedSourceKeys);
                $rows[$index]['missing_natural_key_count'] = count($missingSourceKeys);

                if ([] === $missingSourceKeys) {
                    $rows[$index]['needs_normalization'] = 0;
                    $rows[$index]['projection_status'] = 'natural-key-covered';
                    continue;
                }

                $rows[$index]['projection_status'] = 'natural-key-missing';
            } catch (\Throwable $exception) {
                $rows[$index]['projection_status'] = 'natural-key-check-failed';
                $this->logger->warning('Ozon accrual natural projection coverage check failed.', [
                    'companyId' => $companyId,
                    'rawRecordId' => $rawRecordId,
                    'exceptionClass' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function needsNaturalProjectionCheck(array $row): bool
    {
        return $this->needsNormalization($row)
            && RawNormalizationStatus::DONE->value === (string) $row['normalization_status']
            && 0 === (int) $row['open_issues']
            && 0 === (int) $row['direct_raw_tx_count'];
    }

    /**
     * @return array<string, true>
     */
    private function expectedSourceKeys(string $companyId, string $rawRecordId): array
    {
        $sourceKeys = [];
        foreach ($this->previewMapper->preview($companyId, $this->rawStorageFacade->read($rawRecordId, $companyId), includeSaleRefund: true) as $row) {
            $sourceKeys[$row->sourceKey] = true;
        }

        return $sourceKeys;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    private function rawSummary(array $rows): array
    {
        $summary = [
            'notDoneRaw' => 0,
            'zeroTxRaw' => 0,
            'zeroDirectTxRaw' => 0,
            'openIssueRaw' => 0,
            'naturalKeyMissingRaw' => 0,
        ];

        foreach ($rows as $row) {
            if (RawNormalizationStatus::DONE->value !== (string) $row['normalization_status']) {
                ++$summary['notDoneRaw'];
            }

            if (0 === (int) $row['tx_count']) {
                ++$summary['zeroTxRaw'];
            }

            if (0 === (int) $row['direct_raw_tx_count']) {
                ++$summary['zeroDirectTxRaw'];
            }

            if ((int) $row['open_issues'] > 0) {
                ++$summary['openIssueRaw'];
            }

            if ('natural-key-missing' === ($row['projection_status'] ?? null)) {
                ++$summary['naturalKeyMissingRaw'];
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function withoutRows(array $result): array
    {
        unset($result['rows']);

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $rawRows
     * @param list<array<string, mixed>> $normalizationRows
     * @param list<array<string, mixed>> $relinkRows
     */
    private function printReport(
        SymfonyStyle $io,
        array $payload,
        array $rawRows,
        array $normalizationRows,
        array $relinkRows,
        bool $summaryOnly,
    ): void {
        $io->title('Ozon accrual financial projection reconciliation');
        $io->table(
            ['setting', 'value'],
            [
                ['mode', (string) $payload['mode']],
                ['normalizationMode', (string) $payload['normalizationMode']],
                ['from', (string) $payload['from']],
                ['to', (string) $payload['to']],
                ['daysBack', null === $payload['daysBack'] ? 'custom' : (string) $payload['daysBack']],
                ['companyId', (string) ($payload['companyId'] ?? 'all')],
                ['shopRef', (string) ($payload['shopRef'] ?? 'all')],
                ['repairEnrichmentOnly', true === $payload['repairEnrichmentOnly'] ? 'yes' : 'no'],
                ['rawLimit', (string) $payload['rawLimit']],
                ['relinkLimit', (string) $payload['relinkLimit']],
                ['maxRelinkBatches', (string) $payload['maxRelinkBatches']],
            ],
        );

        $io->section('Projection health');
        $io->table(
            ['metric', 'value'],
            [
                ['rawProblemsSelected', (string) $payload['rawProblems']['selected']],
                ['rawNeedsNormalization', (string) $payload['rawProblems']['needsNormalization']],
                ['notDoneRaw', (string) $payload['rawProblems']['summary']['notDoneRaw']],
                ['zeroTxRaw', (string) $payload['rawProblems']['summary']['zeroTxRaw']],
                ['zeroDirectTxRaw', (string) $payload['rawProblems']['summary']['zeroDirectTxRaw']],
                ['openIssueRaw', (string) $payload['rawProblems']['summary']['openIssueRaw']],
                ['naturalKeyMissingRaw', (string) $payload['rawProblems']['summary']['naturalKeyMissingRaw']],
                ['relinkDeferred', true === $payload['relinkDeferred'] ? 'yes' : 'no'],
            ],
        );

        if (!$summaryOnly && [] !== $rawRows) {
            $io->section('Raw projection problems');
            $io->table(
                ['companyId', 'shopRef', 'windowFrom', 'windowTo', 'rawId', 'status', 'projectionStatus', 'txCount', 'directTxCount', 'expectedTx', 'projectedKeys', 'missingKeys', 'openIssues', 'fetchedAt', 'lastTxUpdatedAt'],
                array_map(static fn (array $row): array => [
                    (string) $row['company_id'],
                    (string) $row['shop_ref'],
                    (string) $row['window_from'],
                    (string) $row['window_to'],
                    (string) $row['raw_id'],
                    (string) $row['normalization_status'],
                    (string) ($row['projection_status'] ?? ''),
                    (string) $row['tx_count'],
                    (string) $row['direct_raw_tx_count'],
                    (string) ($row['expected_tx_count'] ?? ''),
                    (string) ($row['projected_natural_key_count'] ?? ''),
                    (string) ($row['missing_natural_key_count'] ?? ''),
                    (string) $row['open_issues'],
                    (string) $row['fetched_at'],
                    (string) ($row['last_tx_updated_at'] ?? ''),
                ], $rawRows),
            );
        }

        $io->section('Normalization action');
        $this->printMetrics($io, $payload['normalization']);
        if (!$summaryOnly && [] !== $normalizationRows) {
            $io->table(
                ['rawId', 'status', 'txCount', 'openIssues', 'error'],
                array_map(static fn (array $row): array => [
                    (string) $row['rawId'],
                    (string) $row['status'],
                    (string) $row['txCount'],
                    (string) $row['openIssues'],
                    (string) ($row['error'] ?? ''),
                ], $normalizationRows),
            );
        }

        $io->section('Listing enrichment repair');
        $this->printMetrics($io, $payload['relink']);
        if (!$summaryOnly && [] !== $relinkRows) {
            $io->table(
                ['companyId', 'occurredAt', 'externalId', 'listingSku', 'listingId', 'status'],
                array_map(static fn (array $row): array => [
                    (string) $row['companyId'],
                    (string) $row['occurredAt'],
                    (string) $row['externalId'],
                    (string) $row['listingSku'],
                    (string) $row['listingId'],
                    (string) $row['status'],
                ], $relinkRows),
            );
        }

        $io->section('Integrity');
        $io->table(
            ['metric', 'before', 'after'],
            [
                ['txCount', (string) $payload['integrityBefore']['txCount'], (string) $payload['integrityAfter']['txCount']],
                ['unlinkedTotal', (string) $payload['integrityBefore']['unlinkedTotal'], (string) $payload['integrityAfter']['unlinkedTotal']],
                ['unlinkedNonItemFee', (string) $payload['integrityBefore']['unlinkedNonItemFee'], (string) $payload['integrityAfter']['unlinkedNonItemFee']],
                ['brokenLinks', (string) $payload['integrityBefore']['brokenLinks'], (string) $payload['integrityAfter']['brokenLinks']],
                ['skuMismatch', (string) $payload['integrityBefore']['skuMismatch'], (string) $payload['integrityAfter']['skuMismatch']],
            ],
        );
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function printMetrics(SymfonyStyle $io, array $metrics): void
    {
        $io->table(
            ['metric', 'value'],
            array_map(
                static fn (string $key, mixed $value): array => [$key, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value],
                array_keys($metrics),
                $metrics,
            ),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function exitCode(array $payload): int
    {
        if (($payload['normalization']['failed'] ?? 0) > 0) {
            return Command::FAILURE;
        }

        if (true === $payload['relinkDeferred']) {
            return Command::SUCCESS;
        }

        if (($payload['relink']['finalRecoverable'] ?? 0) > 0) {
            return Command::FAILURE;
        }

        if (($payload['integrityAfter']['brokenLinks'] ?? 0) > 0 || ($payload['integrityAfter']['skuMismatch'] ?? 0) > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
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
        $today = $this->clock->now()
            ->setTimezone(new \DateTimeZone(self::BUSINESS_TIMEZONE))
            ->setTime(0, 0);

        return [
            $today->modify(sprintf('-%d days', $daysBack)),
            $today->modify('-1 day'),
            $daysBack,
        ];
    }

    private function optionalUuidOption(InputInterface $input, string $name): ?string
    {
        $value = trim((string) ($input->getOption($name) ?? ''));
        if ('' === $value) {
            return null;
        }

        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException(sprintf('--%s must be a valid UUID.', $name));
        }

        return $value;
    }

    private function optionalDateOption(InputInterface $input, string $name): ?\DateTimeImmutable
    {
        $value = trim((string) ($input->getOption($name) ?? ''));
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('--%s must use YYYY-MM-DD format.', $name));
        }

        return $date;
    }

    private function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $value = trim((string) ($input->getOption($name) ?? ''));

        return '' === $value ? null : $value;
    }

    private function intOption(InputInterface $input, string $name, int $min, int $max): int
    {
        $raw = $input->getOption($name);
        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException(sprintf('--%s must be numeric.', $name));
        }

        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(sprintf('--%s must be between %d and %d.', $name, $min, $max));
        }

        return $value;
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

    private function normalizationMode(InputInterface $input, bool $execute): ?string
    {
        $dispatch = (bool) $input->getOption('dispatch-normalization');
        $inline = (bool) $input->getOption('execute-inline-normalization');

        if ($dispatch && $inline) {
            throw new \InvalidArgumentException('Choose only one normalization mode.');
        }

        if (!$execute) {
            return null;
        }

        if ($dispatch) {
            return 'dispatch';
        }

        if ($inline) {
            return 'inline';
        }

        return null;
    }
}
