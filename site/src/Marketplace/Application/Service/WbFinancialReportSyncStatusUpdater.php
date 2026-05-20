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
use Ramsey\Uuid\Uuid;

final readonly class WbFinancialReportSyncStatusUpdater
{
    public function __construct(
        private MarketplaceFinancialReportSyncStatusRepository $statusRepository,
        private MarketplaceFinancialReportSyncErrorRepository $errorRepository,
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
