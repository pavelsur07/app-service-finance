<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\WbFinancialReportSyncStatusUpdater;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncError;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncErrorRepository;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class WbFinancialReportSyncStatusUpdaterTest extends IntegrationTestCase
{
    private WbFinancialReportSyncStatusUpdater $updater;
    private MarketplaceFinancialReportSyncStatusRepository $statusRepository;
    private MarketplaceFinancialReportSyncErrorRepository $errorRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->updater = self::getContainer()->get(WbFinancialReportSyncStatusUpdater::class);
        $this->statusRepository = self::getContainer()->get(MarketplaceFinancialReportSyncStatusRepository::class);
        $this->errorRepository = self::getContainer()->get(MarketplaceFinancialReportSyncErrorRepository::class);
    }

    public function testStartLoadingCreatesStatusAndIncrementsAttempts(): void
    {
        $status = $this->updater->startLoading(
            $this->connectionId(),
            $this->companyId(),
            'sales',
            '/api/v1/report',
            $this->businessDate(),
            FinancialReportSyncMode::DAILY,
        );

        $this->em->flush();
        $this->em->clear();

        $persisted = $this->findStatus();

        self::assertNotNull($persisted);
        self::assertSame($status->getId(), $persisted->getId());
        self::assertSame(FinancialReportSyncStatus::LOADING, $persisted->getStatus());
        self::assertSame(1, $persisted->getAttempts());
    }

    public function testMarkRawLoadedPersistsRawFields(): void
    {
        $status = $this->startLoadingStatus();

        $this->updater->markRawLoaded($status, $this->rawId(), 55, 'hash-1');
        $this->em->flush();
        $this->em->clear();

        $persisted = $this->findStatus();
        self::assertNotNull($persisted);
        self::assertSame(FinancialReportSyncStatus::RAW_LOADED, $persisted->getStatus());
        self::assertSame($this->rawId(), $persisted->getRawDocumentId());
        self::assertSame(55, $persisted->getRecordsCount());
        self::assertSame('hash-1', $persisted->getRowsHash());
    }

    public function testMarkSuccessPersistsSuccessState(): void
    {
        $status = $this->startLoadingStatus();
        $this->updater->markSuccess($status);
        $this->em->flush();
        $this->em->clear();

        $persisted = $this->findStatus();
        self::assertNotNull($persisted);
        self::assertSame(FinancialReportSyncStatus::SUCCESS, $persisted->getStatus());
        self::assertNotNull($persisted->getLastSuccessAt());
    }

    public function testMarkProcessingPersistsProcessingState(): void
    {
        $status = $this->startLoadingStatus();
        $this->updater->markProcessing($status);
        $this->em->flush();
        $this->em->clear();

        $persisted = $this->findStatus();
        self::assertNotNull($persisted);
        self::assertSame(FinancialReportSyncStatus::PROCESSING, $persisted->getStatus());
    }

    public function testMarkEmptyPersistsEmptyStateAndZeroRecords(): void
    {
        $status = $this->startLoadingStatus();
        $this->updater->markEmpty($status);
        $this->em->flush();
        $this->em->clear();

        $persisted = $this->findStatus();
        self::assertNotNull($persisted);
        self::assertSame(FinancialReportSyncStatus::EMPTY, $persisted->getStatus());
        self::assertSame(0, $persisted->getRecordsCount());
    }

    public function testMarkFailedRetryablePersistsFailedStateNextRetryAndError(): void
    {
        $status = $this->startLoadingStatus();
        $nextRetryAt = new \DateTimeImmutable('2026-05-01 10:30:00');

        $this->updater->markFailedRetryable($status, 'HttpException', 'temporary failure', 503, 'timeout', ['period' => '2026-05'], $nextRetryAt);
        $this->em->flush();
        $this->em->clear();

        $persisted = $this->findStatus();
        self::assertNotNull($persisted);
        self::assertSame(FinancialReportSyncStatus::FAILED, $persisted->getStatus());
        self::assertSame($nextRetryAt->format(DATE_ATOM), $persisted->getNextRetryAt()?->format(DATE_ATOM));

        $errors = $this->errorRepository->findBy(['syncStatusId' => $persisted->getId()]);
        self::assertCount(1, $errors);
    }

    public function testMarkFailedFinalPersistsStatusAndCreatesError(): void
    {
        $status = $this->startLoadingStatus();
        $this->updater->markFailedFinal($status, 'ValidationException', 'bad payload', 422);
        $this->em->flush();
        $this->em->clear();

        $persisted = $this->findStatus();
        self::assertNotNull($persisted);
        self::assertSame(FinancialReportSyncStatus::FAILED_FINAL, $persisted->getStatus());

        $errors = $this->errorRepository->findBy(['syncStatusId' => $persisted->getId()]);
        self::assertCount(1, $errors);
        self::assertContainsOnlyInstancesOf(MarketplaceFinancialReportSyncError::class, $errors);
    }

    public function testMarkAuthFailedPersistsStatusAndCreatesError(): void
    {
        $status = $this->startLoadingStatus();
        $this->updater->markAuthFailed($status, 'AuthException', 'token expired', 401);
        $this->em->flush();
        $this->em->clear();

        $persisted = $this->findStatus();
        self::assertNotNull($persisted);
        self::assertSame(FinancialReportSyncStatus::AUTH_FAILED, $persisted->getStatus());

        $errors = $this->errorRepository->findBy(['syncStatusId' => $persisted->getId()]);
        self::assertCount(1, $errors);
        self::assertContainsOnlyInstancesOf(MarketplaceFinancialReportSyncError::class, $errors);
    }

    public function testMarkConflictPersistsStatusAndCreatesError(): void
    {
        $status = $this->startLoadingStatus();
        $this->updater->markConflict($status, 'ConflictException', 'conflict', 409);
        $this->em->flush();
        $this->em->clear();

        $persisted = $this->findStatus();
        self::assertNotNull($persisted);
        self::assertSame(FinancialReportSyncStatus::CONFLICT, $persisted->getStatus());

        $errors = $this->errorRepository->findBy(['syncStatusId' => $persisted->getId()]);
        self::assertCount(1, $errors);
        self::assertContainsOnlyInstancesOf(MarketplaceFinancialReportSyncError::class, $errors);
    }


    public function testSyncByRawPipelineResultMarksSuccessForCompletedWbSalesReport(): void
    {
        $status = $this->startLoadingStatus();
        $this->updater->markRawLoaded($status, $this->rawId(), 1, 'h');
        $this->updater->markProcessing($status);

        $company = $this->em->getReference(\App\Company\Entity\Company::class, $this->companyId());
        $raw = new \App\Marketplace\Entity\MarketplaceRawDocument(
            $this->rawId(),
            $company,
            \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
            'sales_report',
        );
        $raw->markCompleted();

        $this->updater->syncByRawPipelineResult($raw);
        $this->em->flush();
        $this->em->clear();

        $persisted = $this->findStatus();
        self::assertNotNull($persisted);
        self::assertSame(FinancialReportSyncStatus::SUCCESS, $persisted->getStatus());
        self::assertNotNull($persisted->getFinishedAt());
        self::assertNotNull($persisted->getLastSuccessAt());
    }


    public function testSyncByRawPipelineResultMarksFailedFinalAndSavesError(): void
    {
        $status = $this->startLoadingStatus();
        $this->updater->markRawLoaded($status, $this->rawId(), 1, 'h');
        $this->updater->markProcessing($status);

        $company = $this->em->getReference(\App\Company\Entity\Company::class, $this->companyId());
        $raw = new \App\Marketplace\Entity\MarketplaceRawDocument(
            $this->rawId(),
            $company,
            \App\Marketplace\Enum\MarketplaceType::WILDBERRIES,
            'sales_report',
        );
        $raw->markStepFailed(\App\Marketplace\Enum\PipelineStep::SALES);

        $this->updater->syncByRawPipelineResult($raw, new \RuntimeException('pipeline exploded'));
        $this->em->flush();
        $this->em->clear();

        $persisted = $this->findStatus();
        self::assertNotNull($persisted);
        self::assertSame(FinancialReportSyncStatus::FAILED_FINAL, $persisted->getStatus());

        $errors = $this->errorRepository->findBy(['syncStatusId' => $persisted->getId()]);
        self::assertNotEmpty($errors);
    }

    public function testSyncByRawPipelineResultSkipsNonWbOrNonSalesReport(): void
    {
        $status = $this->startLoadingStatus();
        $this->updater->markRawLoaded($status, $this->rawId(), 1, 'h');
        $this->updater->markProcessing($status);

        $company = $this->em->getReference(\App\Company\Entity\Company::class, $this->companyId());
        $raw = new \App\Marketplace\Entity\MarketplaceRawDocument(
            '00000000-0000-0000-0000-000000000100',
            $company,
            \App\Marketplace\Enum\MarketplaceType::OZON,
            'sales_report',
        );
        $raw->markCompleted();

        $this->updater->syncByRawPipelineResult($raw);
        $this->em->flush();
        $this->em->clear();

        $persisted = $this->findStatus();
        self::assertNotNull($persisted);
        self::assertSame(FinancialReportSyncStatus::PROCESSING, $persisted->getStatus());
    }

    private function startLoadingStatus(): MarketplaceFinancialReportSyncStatus
    {
        return $this->updater->startLoading(
            $this->connectionId(),
            $this->companyId(),
            'sales',
            '/api/v1/report',
            $this->businessDate(),
            FinancialReportSyncMode::INITIAL,
        );
    }

    private function findStatus(): ?MarketplaceFinancialReportSyncStatus
    {
        return $this->statusRepository->findByConnectionAndDate(
            $this->connectionId(),
            $this->companyId(),
            $this->businessDate(),
            'sales',
        );
    }

    private function companyId(): string { return '00000000-0000-0000-0000-000000000001'; }
    private function connectionId(): string { return '00000000-0000-0000-0000-000000000002'; }
    private function rawId(): string { return '00000000-0000-0000-0000-000000000099'; }
    private function businessDate(): \DateTimeImmutable { return new \DateTimeImmutable('2026-05-01'); }
}
