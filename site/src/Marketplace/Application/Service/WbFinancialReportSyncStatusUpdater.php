<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncError;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncErrorRepository;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\PipelineStatus;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final readonly class WbFinancialReportSyncStatusUpdater implements WbFinancialReportSyncStatusUpdaterInterface
{
    public function __construct(
        private MarketplaceFinancialReportSyncStatusRepository $statusRepository,
        private MarketplaceFinancialReportSyncErrorRepository $errorRepository,
        private LoggerInterface $logger,
    ) {}


    public function findOrCreateForDay(string $connectionId, string $companyId, string $reportType, string $apiEndpoint, \DateTimeImmutable $businessDate): MarketplaceFinancialReportSyncStatus
    {
        $status = $this->statusRepository->findOrCreateForDay($connectionId, $companyId, MarketplaceType::WILDBERRIES, $reportType, $apiEndpoint, $businessDate);
        $this->statusRepository->save($status);

        return $status;
    }

    public function startLoading(string $connectionId, string $companyId, string $reportType, string $apiEndpoint, \DateTimeImmutable $businessDate, FinancialReportSyncMode $mode): MarketplaceFinancialReportSyncStatus
    {
        $status = $this->statusRepository->findOrCreateForDay($connectionId, $companyId, MarketplaceType::WILDBERRIES, $reportType, $apiEndpoint, $businessDate);
        $status->markLoading($mode);
        $this->statusRepository->save($status);

        return $status;
    }

    public function markEmpty(MarketplaceFinancialReportSyncStatus $status): void
    {
        $status->markEmpty();
        $this->statusRepository->save($status);
    }

    public function markRawLoaded(MarketplaceFinancialReportSyncStatus $status, string $rawDocumentId, int $recordsCount, ?string $rowsHash): void
    {
        $status->markRawLoaded($rawDocumentId, $recordsCount, $rowsHash);
        $this->statusRepository->save($status);
    }

    public function scheduleQueuedRetry(string $connectionId, string $companyId, string $reportType, string $apiEndpoint, \DateTimeImmutable $businessDate, FinancialReportSyncMode $mode, bool $forceRefresh, \DateTimeImmutable $nextRetryAt, ?string $stagingRawDocumentId = null, ?int $nextRrdId = null): MarketplaceFinancialReportSyncStatus
    {
        $status = $this->statusRepository->findOrCreateForDay($connectionId, $companyId, MarketplaceType::WILDBERRIES, $reportType, $apiEndpoint, $businessDate);
        $this->markPageQueued($status, $mode, $forceRefresh, $nextRetryAt, $stagingRawDocumentId, $nextRrdId);

        return $status;
    }

    public function markLoading(MarketplaceFinancialReportSyncStatus $status, FinancialReportSyncMode $mode): void
    {
        $status->markLoading($mode);
        $this->statusRepository->save($status);
    }

    public function markPageQueued(MarketplaceFinancialReportSyncStatus $status, FinancialReportSyncMode $mode, bool $forceRefresh, \DateTimeImmutable $nextRetryAt, ?string $stagingRawDocumentId = null, ?int $nextRrdId = null): void
    {
        $status->markQueued($mode, $forceRefresh);
        $status->scheduleNextRetryAt($nextRetryAt, $stagingRawDocumentId, $nextRrdId);
        $this->statusRepository->save($status);
    }

    public function markProcessing(MarketplaceFinancialReportSyncStatus $status): void
    {
        $status->markProcessing();
        $this->statusRepository->save($status);
    }

    public function markSuccess(MarketplaceFinancialReportSyncStatus $status): void
    {
        $status->markSuccess();
        $this->statusRepository->save($status);
    }

    /** @param array<string,mixed>|null $requestPayload */
    public function markFailedRetryable(MarketplaceFinancialReportSyncStatus $status, string $errorClass, string $errorMessage, ?int $statusCode = null, ?string $responseExcerpt = null, ?array $requestPayload = null, ?\DateTimeImmutable $nextRetryAt = null): void
    {
        $status->markFailedRetryable($errorClass, $errorMessage, $statusCode, $responseExcerpt, $nextRetryAt);
        $this->statusRepository->save($status);
        $this->saveError($status, $errorClass, $errorMessage, $statusCode, $responseExcerpt, $requestPayload);
    }

    /** @param array<string,mixed>|null $requestPayload */
    public function recordRetryableError(MarketplaceFinancialReportSyncStatus $status, string $errorClass, string $errorMessage, ?int $statusCode = null, ?string $responseExcerpt = null, ?array $requestPayload = null): void
    {
        $status->recordRetryableError($errorClass, $errorMessage, $statusCode, $responseExcerpt);
        $this->statusRepository->save($status);
        $this->saveError($status, $errorClass, $errorMessage, $statusCode, $responseExcerpt, $requestPayload);
    }


    /** @param array<string,mixed>|null $requestPayload */
    public function markFailedRetryablePreservingCursor(
        MarketplaceFinancialReportSyncStatus $status,
        string $errorClass,
        string $errorMessage,
        ?int $statusCode = null,
        ?string $responseExcerpt = null,
        ?array $requestPayload = null,
        ?\DateTimeImmutable $nextRetryAt = null,
        ?string $stagingRawDocumentId = null,
        ?int $nextRrdId = null,
    ): void {
        $status->markFailedRetryablePreservingCursor($errorClass, $errorMessage, $statusCode, $responseExcerpt, $nextRetryAt, $stagingRawDocumentId, $nextRrdId);
        $this->statusRepository->save($status);
        $this->saveError($status, $errorClass, $errorMessage, $statusCode, $responseExcerpt, $requestPayload);
    }

    /** @param array<string,mixed>|null $requestPayload */
    public function markFailedFinal(MarketplaceFinancialReportSyncStatus $status, string $errorClass, string $errorMessage, ?int $statusCode = null, ?string $responseExcerpt = null, ?array $requestPayload = null): void
    {
        $status->markFailedFinal($errorClass, $errorMessage, $statusCode, $responseExcerpt);
        $this->statusRepository->save($status);
        $this->saveError($status, $errorClass, $errorMessage, $statusCode, $responseExcerpt, $requestPayload);
    }

    /** @param array<string,mixed>|null $requestPayload */
    public function markAuthFailed(MarketplaceFinancialReportSyncStatus $status, string $errorClass, string $errorMessage, ?int $statusCode = null, ?string $responseExcerpt = null, ?array $requestPayload = null): void
    {
        $status->markAuthFailed($errorClass, $errorMessage, $statusCode, $responseExcerpt);
        $this->statusRepository->save($status);
        $this->saveError($status, $errorClass, $errorMessage, $statusCode, $responseExcerpt, $requestPayload);
    }

    /** @param array<string,mixed>|null $requestPayload */
    public function markConflict(MarketplaceFinancialReportSyncStatus $status, string $errorClass, string $errorMessage, ?int $statusCode = null, ?string $responseExcerpt = null, ?array $requestPayload = null): void
    {
        $status->markConflict($errorClass, $errorMessage, $statusCode, $responseExcerpt);
        $this->statusRepository->save($status);
        $this->saveError($status, $errorClass, $errorMessage, $statusCode, $responseExcerpt, $requestPayload);
    }


    /** @param array{sync_status_id?: string|null, company_id?: string|null, connection_id?: string|null, marketplace?: string|null, report_type?: string|null, mode?: string|null, business_date?: string|null, raw_document_id?: string|null}|null $context */
    public function syncByRawPipelineResult(MarketplaceRawDocument $rawDocument, ?\Throwable $failure = null, ?array $context = null): void
    {
        if ($rawDocument->getMarketplace() !== MarketplaceType::WILDBERRIES || $rawDocument->getDocumentType() !== 'sales_report') {
            return;
        }

        $companyId = (string) $rawDocument->getCompany()->getId();
        $rawDocumentId = $rawDocument->getId();
        $statuses = $this->statusRepository->findAllByRawDocumentId($companyId, $rawDocumentId);
        if (count($statuses) > 1) {
            $this->logger->warning('Multiple WB sync statuses reference one raw document; exact context is required to finalize safely.', [
                'company_id' => $companyId,
                'raw_document_id' => $rawDocumentId,
                'sync_status_ids' => array_map(static fn (MarketplaceFinancialReportSyncStatus $status): string => $status->getId(), $statuses),
            ]);
        }

        $status = $this->resolveStatusForRawPipelineResult($rawDocument, $context);
        if ($status === null) {
            return;
        }

        $before = $status->getStatus()->value;

        if ($rawDocument->getProcessingStatus() === PipelineStatus::COMPLETED) {
            $status->markSuccess();
        } elseif ($rawDocument->getProcessingStatus() === PipelineStatus::FAILED) {
            $errorClass = null !== $failure ? $failure::class : 'PipelineFailedException';
            $errorMessage = $failure?->getMessage() ?? sprintf('Raw pipeline failed for document %s', $rawDocument->getId());

            if ($failure instanceof \LogicException || $failure instanceof \InvalidArgumentException) {
                $status->markConflict($errorClass, $errorMessage, null, null);
            } else {
                $status->markFailedFinal($errorClass, $errorMessage, null, null);
            }

            $this->saveError($status, $errorClass, $errorMessage, null, null, null);
        } else {
            return;
        }

        $this->statusRepository->save($status);

        $this->logger->info('WB daily sync status changed after raw pipeline result', [
            'rawDocumentId' => $rawDocument->getId(),
            'syncStatusId' => $status->getId(),
            'from' => $before,
            'to' => $status->getStatus()->value,
            'pipelineStatus' => $rawDocument->getProcessingStatus()?->value,
        ]);
    }

    /** @param array{sync_status_id?: string|null, company_id?: string|null, connection_id?: string|null, marketplace?: string|null, report_type?: string|null, mode?: string|null, business_date?: string|null, raw_document_id?: string|null}|null $context */
    private function resolveStatusForRawPipelineResult(MarketplaceRawDocument $rawDocument, ?array $context): ?MarketplaceFinancialReportSyncStatus
    {
        $companyId = (string) $rawDocument->getCompany()->getId();
        $rawDocumentId = $rawDocument->getId();

        if (null === $context || null === ($context['connection_id'] ?? null)) {
            $statuses = $this->statusRepository->findAllByRawDocumentId($companyId, $rawDocumentId);
            if (count($statuses) > 1) {
                $this->logger->warning('WB sync status finalization skipped because raw document is linked to multiple statuses and no exact context was provided.', [
                    'company_id' => $companyId,
                    'raw_document_id' => $rawDocumentId,
                    'sync_status_ids' => array_map(static fn (MarketplaceFinancialReportSyncStatus $status): string => $status->getId(), $statuses),
                ]);

                return null;
            }

            return $statuses[0] ?? null;
        }

        if (isset($context['company_id']) && $context['company_id'] !== $companyId) {
            $this->logger->warning('WB sync status finalization skipped because context company does not match raw document company.', [
                'company_id' => $companyId,
                'raw_document_id' => $rawDocumentId,
                'context' => $context,
            ]);

            return null;
        }

        $marketplace = MarketplaceType::tryFrom((string) ($context['marketplace'] ?? MarketplaceType::WILDBERRIES->value));
        $mode = FinancialReportSyncMode::tryFrom((string) ($context['mode'] ?? ''));
        $businessDate = $this->parseBusinessDate($context['business_date'] ?? null);
        $reportType = $context['report_type'] ?? null;
        $contextRawDocumentId = $context['raw_document_id'] ?? $rawDocumentId;

        if ($marketplace === null || $mode === null || $businessDate === null || !is_string($reportType) || $reportType === '' || $contextRawDocumentId !== $rawDocumentId) {
            $this->logger->warning('WB sync status finalization skipped because exact context is incomplete or mismatched.', [
                'company_id' => $companyId,
                'raw_document_id' => $rawDocumentId,
                'context' => $context,
            ]);

            return null;
        }

        $status = $this->statusRepository->findByRawPipelineContext(
            $context['sync_status_id'] ?? null,
            $companyId,
            (string) $context['connection_id'],
            $marketplace,
            $reportType,
            $mode,
            $businessDate,
            $rawDocumentId,
        );

        if ($status === null) {
            $this->logger->warning('WB sync status finalization skipped: no status matches exact raw pipeline context.', [
                'company_id' => $companyId,
                'raw_document_id' => $rawDocumentId,
                'context' => $context,
            ]);
        }

        return $status;
    }

    private function parseBusinessDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->setTime(0, 0);
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTime(0, 0);
        }

        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTime(0, 0);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed>|null $requestPayload */
    private function saveError(MarketplaceFinancialReportSyncStatus $status, string $errorClass, string $errorMessage, ?int $statusCode, ?string $responseExcerpt, ?array $requestPayload): void
    {
        $this->errorRepository->save(new MarketplaceFinancialReportSyncError(
            Uuid::uuid7()->toString(),
            $status->getId(),
            $status->getCompanyId(),
            $status->getConnectionId(),
            $status->getBusinessDate(),
            $errorClass,
            $errorMessage,
            $statusCode,
            $responseExcerpt,
            $requestPayload,
        ));
    }
}
