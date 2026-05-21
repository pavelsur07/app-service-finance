<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class MarketplaceFinancialReportSyncStatusRepositoryTest extends IntegrationTestCase
{
    private const REPORT_TYPE = 'sales_report';

    private MarketplaceFinancialReportSyncStatusRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(MarketplaceFinancialReportSyncStatusRepository::class);
    }

    public function testFindRetryDueDaysReturnsOnlyRetryableRowsWithFiltersLimitAndAscOrder(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $connectionId = '22222222-2222-4222-8222-222222222222';
        $this->seedActiveConnection($companyId, $connectionId);

        $now = new \DateTimeImmutable('2026-01-05 12:00:00');

        $this->persistStatus($companyId, $connectionId, '2026-01-01', FinancialReportSyncStatus::FAILED, null);
        $this->persistStatus($companyId, $connectionId, '2026-01-02', FinancialReportSyncStatus::FAILED, new \DateTimeImmutable('2026-01-05 11:00:00'));
        $this->persistStatus($companyId, $connectionId, '2026-01-03', FinancialReportSyncStatus::FAILED, new \DateTimeImmutable('2026-01-05 13:00:00'));
        $this->persistStatus($companyId, $connectionId, '2026-01-04', FinancialReportSyncStatus::SUCCESS);
        $this->persistStatus($companyId, $connectionId, '2026-01-05', FinancialReportSyncStatus::EMPTY);
        $this->persistStatus($companyId, $connectionId, '2026-01-06', FinancialReportSyncStatus::LOADING);
        $this->persistStatus($companyId, $connectionId, '2026-01-07', FinancialReportSyncStatus::PROCESSING);

        $otherCompanyId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $otherConnectionId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $this->seedActiveConnection($otherCompanyId, $otherConnectionId);
        $this->persistStatus($otherCompanyId, $connectionId, '2026-01-01', FinancialReportSyncStatus::FAILED, null);
        $this->persistStatus($companyId, $otherConnectionId, '2026-01-01', FinancialReportSyncStatus::FAILED, null);
        $this->persistStatus($companyId, $connectionId, '2026-01-08', FinancialReportSyncStatus::FAILED, null, 'another_report');

        $days = $this->repository->findRetryDueDays(
            $companyId,
            $connectionId,
            self::REPORT_TYPE,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-01-10 00:00:00'),
            $now,
            2,
        );

        self::assertSame(['2026-01-01', '2026-01-02'], array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $days));
    }


    public function testFindStatusesForDateRangeFiltersByScopeRangeAndSortsAsc(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $connectionId = '22222222-2222-4222-8222-222222222222';
        $this->seedActiveConnection($companyId, $connectionId);

        $this->persistStatus($companyId, $connectionId, '2025-12-31', FinancialReportSyncStatus::FAILED);
        $this->persistStatus($companyId, $connectionId, '2026-01-03', FinancialReportSyncStatus::FAILED);
        $this->persistStatus($companyId, $connectionId, '2026-01-01', FinancialReportSyncStatus::SUCCESS);
        $this->persistStatus($companyId, $connectionId, '2026-01-02', FinancialReportSyncStatus::EMPTY);
        $this->persistStatus($companyId, $connectionId, '2026-01-04', FinancialReportSyncStatus::FAILED, null, 'another_report');

        $otherCompanyId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $otherConnectionId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $this->seedActiveConnection($otherCompanyId, $otherConnectionId);
        $this->persistStatus($otherCompanyId, $connectionId, '2026-01-02', FinancialReportSyncStatus::FAILED);
        $this->persistStatus($companyId, $otherConnectionId, '2026-01-02', FinancialReportSyncStatus::FAILED);

        $statuses = $this->repository->findStatusesForDateRange(
            $companyId,
            $connectionId,
            self::REPORT_TYPE,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-01-03 23:59:59'),
        );

        self::assertSame(
            ['2026-01-01', '2026-01-02', '2026-01-03'],
            array_map(static fn (MarketplaceFinancialReportSyncStatus $status): string => $status->getBusinessDate()->format('Y-m-d'), $statuses),
        );
    }

    private function persistStatus(
        string $companyId,
        string $connectionId,
        string $day,
        FinancialReportSyncStatus $status,
        ?\DateTimeImmutable $nextRetryAt = null,
        string $reportType = self::REPORT_TYPE,
    ): void {
        $entity = new MarketplaceFinancialReportSyncStatus(
            Uuid::uuid7()->toString(),
            $companyId,
            $connectionId,
            MarketplaceType::WILDBERRIES,
            $reportType,
            'endpoint',
            new \DateTimeImmutable($day),
        );

        match ($status) {
            FinancialReportSyncStatus::SUCCESS => $entity->markSuccess(),
            FinancialReportSyncStatus::EMPTY => $entity->markEmpty(),
            FinancialReportSyncStatus::LOADING => $entity->markLoading(FinancialReportSyncMode::DAILY),
            FinancialReportSyncStatus::PROCESSING => $entity->markProcessing(),
            FinancialReportSyncStatus::FAILED => $entity->markFailedRetryable('TestException', 'failed', 500, null, $nextRetryAt),
            default => null,
        };

        $this->repository->save($entity);
        $this->em->flush();
    }

    private function seedActiveConnection(string $companyId, string $connectionId): void
    {
        $existing = $this->em->find(Company::class, $companyId);
        if (!$existing instanceof Company) {
            $company = CompanyBuilder::aCompany()->withId($companyId)->build();
            $this->em->persist($company->getOwner());
            $this->em->persist($company);
            $this->em->flush();
        }

        $this->em->getConnection()->insert('marketplace_connections', [
            'id' => $connectionId,
            'company_id' => $companyId,
            'marketplace' => 'wildberries',
            'connection_type' => 'seller',
            'api_key' => 'encrypted-key',
            'is_active' => true,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
