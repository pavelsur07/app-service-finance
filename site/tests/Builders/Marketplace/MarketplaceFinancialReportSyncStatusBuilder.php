<?php

declare(strict_types=1);

namespace App\Tests\Builders\Marketplace;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use Ramsey\Uuid\Uuid;

/**
 * Статус дня WB sales_report в нужном состоянии — только через реальные переходы сущности.
 */
final class MarketplaceFinancialReportSyncStatusBuilder
{
    private string $companyId = '11111111-1111-1111-1111-111111111111';
    private string $connectionId = '22222222-2222-4222-8222-222222222222';
    private MarketplaceType $marketplace = MarketplaceType::WILDBERRIES;
    private string $reportType = 'sales_report';
    private string $businessDate = '2026-01-01';
    private FinancialReportSyncStatus $status = FinancialReportSyncStatus::SUCCESS;

    private function __construct()
    {
    }

    public static function aStatus(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withConnectionId(string $connectionId): self
    {
        $clone = clone $this;
        $clone->connectionId = $connectionId;

        return $clone;
    }

    public function withBusinessDate(string $businessDate): self
    {
        $clone = clone $this;
        $clone->businessDate = $businessDate;

        return $clone;
    }

    public function withStatus(FinancialReportSyncStatus $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function build(): MarketplaceFinancialReportSyncStatus
    {
        $entity = new MarketplaceFinancialReportSyncStatus(
            Uuid::uuid7()->toString(),
            $this->companyId,
            $this->connectionId,
            $this->marketplace,
            $this->reportType,
            'wildberries::finance-sales-reports-detailed',
            new \DateTimeImmutable($this->businessDate),
        );

        match ($this->status) {
            FinancialReportSyncStatus::QUEUED => $entity->markQueued(FinancialReportSyncMode::DAILY, false),
            FinancialReportSyncStatus::LOADING => $entity->markLoading(FinancialReportSyncMode::DAILY),
            FinancialReportSyncStatus::RAW_LOADED => $this->rawLoaded($entity),
            FinancialReportSyncStatus::PROCESSING => $this->processing($entity),
            FinancialReportSyncStatus::SUCCESS => $entity->markSuccess(),
            FinancialReportSyncStatus::EMPTY => $entity->markEmpty(),
            FinancialReportSyncStatus::FAILED => $entity->markFailedRetryable('TestException', 'failed', 500, null, null),
            FinancialReportSyncStatus::FAILED_FINAL => $entity->markFailedFinal('TestException', 'failed final', 400, null),
            FinancialReportSyncStatus::AUTH_FAILED => $entity->markAuthFailed('TestException', 'auth failed', 401, null),
            FinancialReportSyncStatus::CONFLICT => $entity->markConflict('TestException', 'conflict', 409, null),
        };

        return $entity;
    }

    private function rawLoaded(MarketplaceFinancialReportSyncStatus $entity): void
    {
        $entity->markLoading(FinancialReportSyncMode::DAILY);
        $entity->markRawLoaded(Uuid::uuid4()->toString(), 10, 'hash');
    }

    private function processing(MarketplaceFinancialReportSyncStatus $entity): void
    {
        $this->rawLoaded($entity);
        $entity->markProcessing();
    }
}
