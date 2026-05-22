<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncError;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
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


    public function syncByRawPipelineResult(MarketplaceRawDocument $rawDocument, ?\Throwable $failure = null): void
    {
        if ($rawDocument->getMarketplace() !== MarketplaceType::WILDBERRIES || $rawDocument->getDocumentType() !== 'sales_report') {
            return;
        }

        $status = $this->statusRepository->findByRawDocumentId((string) $rawDocument->getCompany()->getId(), $rawDocument->getId());
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
