<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
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

        $this->persistStatus($companyId, $connectionId, '2026-01-01', FinancialReportSyncStatus::FAILED, new \DateTimeImmutable('2026-01-05 10:00:00'));
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
        $this->persistStatus($companyId, $connectionId, '2026-01-08', FinancialReportSyncStatus::FAILED, null, 'another_report');

        $days = $this->repository->findRetryDueDays(
            $companyId,
            $connectionId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-01-10 00:00:00'),
            $now,
            2,
        );

        self::assertSame(['2026-01-01', '2026-01-02'], array_map(static fn (array $item): string => $item['business_date']->format('Y-m-d'), $days));
        self::assertSame([FinancialReportSyncMode::MISSING, FinancialReportSyncMode::MISSING], array_map(static fn (array $item): FinancialReportSyncMode => $item['mode'], $days));
    }

    public function testFindRetryDueDaysReturnsStuckQueuedAndLoadingOlderThanReclaimInterval(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $connectionId = '22222222-2222-4222-8222-222222222222';
        $this->seedActiveConnection($companyId, $connectionId);

        $this->persistStatus($companyId, $connectionId, '2026-01-01', FinancialReportSyncStatus::QUEUED);
        $this->persistStatus($companyId, $connectionId, '2026-01-02', FinancialReportSyncStatus::QUEUED);
        $this->persistStatus($companyId, $connectionId, '2026-01-03', FinancialReportSyncStatus::LOADING);
        $this->persistStatus($companyId, $connectionId, '2026-01-04', FinancialReportSyncStatus::LOADING);

        $this->backdateStatusUpdatedAt($companyId, '2026-01-01');
        $this->backdateStatusUpdatedAt($companyId, '2026-01-03');
        $this->em->clear();

        $days = $this->repository->findRetryDueDays(
            $companyId,
            $connectionId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-01-10 00:00:00'),
            new \DateTimeImmutable(),
            10,
        );

        self::assertSame(
            ['2026-01-01', '2026-01-03'],
            array_map(static fn (array $item): string => $item['business_date']->format('Y-m-d'), $days),
        );
        self::assertSame(
            [FinancialReportSyncMode::MISSING, FinancialReportSyncMode::DAILY],
            array_map(static fn (array $item): FinancialReportSyncMode => $item['mode'], $days),
        );
    }

    public function testClaimForQueueReclaimsOnlyStuckQueuedAndLoading(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $connectionId = '22222222-2222-4222-8222-222222222222';
        $this->seedActiveConnection($companyId, $connectionId);

        $this->persistStatus($companyId, $connectionId, '2026-01-01', FinancialReportSyncStatus::QUEUED);
        $this->persistStatus($companyId, $connectionId, '2026-01-02', FinancialReportSyncStatus::QUEUED);
        $this->persistStatus($companyId, $connectionId, '2026-01-03', FinancialReportSyncStatus::LOADING);
        $this->persistStatus($companyId, $connectionId, '2026-01-04', FinancialReportSyncStatus::LOADING);

        $this->backdateStatusUpdatedAt($companyId, '2026-01-01');
        $this->backdateStatusUpdatedAt($companyId, '2026-01-03');
        $this->em->clear();

        $now = new \DateTimeImmutable();
        $claim = fn (string $day) => $this->repository->claimForQueue(
            $connectionId,
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            'endpoint',
            new \DateTimeImmutable($day),
            FinancialReportSyncMode::MISSING,
            false,
            $now,
        );

        $stuckQueued = $claim('2026-01-01 00:00:00');
        self::assertInstanceOf(MarketplaceFinancialReportSyncStatus::class, $stuckQueued);
        self::assertSame(FinancialReportSyncStatus::QUEUED, $stuckQueued->getStatus());
        self::assertSame(FinancialReportSyncMode::MISSING, $stuckQueued->getMode());

        self::assertNull($claim('2026-01-02 00:00:00'), 'Свежий QUEUED без next_retry_at ждёт своё сообщение и не переоткрывается.');

        $stuckLoading = $claim('2026-01-03 00:00:00');
        self::assertInstanceOf(MarketplaceFinancialReportSyncStatus::class, $stuckLoading);
        self::assertSame(FinancialReportSyncStatus::QUEUED, $stuckLoading->getStatus());

        self::assertNull($claim('2026-01-04 00:00:00'), 'Свежий LOADING — handler ещё работает, переоткрывать нельзя.');
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

        $statuses = $this->repository->findStatusesForDateRange(
            $companyId,
            $connectionId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-01-03 23:59:59'),
        );

        self::assertSame(
            ['2026-01-01', '2026-01-02', '2026-01-03'],
            array_map(static fn (MarketplaceFinancialReportSyncStatus $status): string => $status->getBusinessDate()->format('Y-m-d'), $statuses),
        );
    }

    public function testClaimForQueueCreatesQueuedStatusForMissingDay(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $connectionId = '22222222-2222-4222-8222-222222222222';
        $this->seedActiveConnection($companyId, $connectionId);
        $day = new \DateTimeImmutable('2026-01-01 00:00:00');

        $status = $this->repository->claimForQueue(
            $connectionId,
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            'endpoint',
            $day,
            FinancialReportSyncMode::DAILY,
            false,
            new \DateTimeImmutable('2026-01-05 12:00:00'),
        );

        self::assertInstanceOf(MarketplaceFinancialReportSyncStatus::class, $status);
        self::assertSame(FinancialReportSyncStatus::QUEUED, $status->getStatus());
        self::assertSame(FinancialReportSyncMode::DAILY, $status->getMode());
        self::assertNull($status->getNextRetryAt());
    }

    public function testClaimForQueueSkipsSuccessForDailyWithoutForceAndAllowsForce(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $connectionId = '22222222-2222-4222-8222-222222222222';
        $this->seedActiveConnection($companyId, $connectionId);
        $day = new \DateTimeImmutable('2026-01-01 00:00:00');
        $this->persistStatus($companyId, $connectionId, '2026-01-01', FinancialReportSyncStatus::SUCCESS);

        self::assertNull($this->repository->claimForQueue(
            $connectionId,
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            'endpoint',
            $day,
            FinancialReportSyncMode::DAILY,
            false,
            new \DateTimeImmutable('2026-01-05 12:00:00'),
        ));

        $status = $this->repository->claimForQueue(
            $connectionId,
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            'endpoint',
            $day,
            FinancialReportSyncMode::DAILY,
            true,
            new \DateTimeImmutable('2026-01-05 12:00:00'),
        );

        self::assertInstanceOf(MarketplaceFinancialReportSyncStatus::class, $status);
        self::assertSame(FinancialReportSyncStatus::QUEUED, $status->getStatus());
    }

    public function testClaimForQueueSkipsFutureRetryAndTerminalStatusesExceptManualForce(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $retryConnectionId = '22222222-2222-4222-8222-222222222222';
        $terminalConnectionId = '33333333-3333-4333-8333-333333333333';
        $this->seedActiveConnection($companyId, $retryConnectionId);
        $this->seedConnectionForExistingCompany($companyId, $terminalConnectionId);
        $now = new \DateTimeImmutable('2026-01-05 12:00:00');

        $this->persistStatus($companyId, $retryConnectionId, '2026-01-01', FinancialReportSyncStatus::FAILED, new \DateTimeImmutable('2026-01-05 13:00:00'));
        $this->persistStatus($companyId, $terminalConnectionId, '2026-01-02', FinancialReportSyncStatus::AUTH_FAILED);

        self::assertNull($this->repository->claimForQueue(
            $retryConnectionId,
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            'endpoint',
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            FinancialReportSyncMode::MISSING,
            false,
            $now,
        ));
        self::assertNull($this->repository->claimForQueue(
            $terminalConnectionId,
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            'endpoint',
            new \DateTimeImmutable('2026-01-02 00:00:00'),
            FinancialReportSyncMode::DAILY,
            true,
            $now,
        ));

        $status = $this->repository->claimForQueue(
            $terminalConnectionId,
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            'endpoint',
            new \DateTimeImmutable('2026-01-02 00:00:00'),
            FinancialReportSyncMode::MANUAL,
            true,
            $now,
        );

        self::assertInstanceOf(MarketplaceFinancialReportSyncStatus::class, $status);
        self::assertSame(FinancialReportSyncStatus::QUEUED, $status->getStatus());
        self::assertSame(FinancialReportSyncMode::MANUAL, $status->getMode());
    }

    public function testFindByBusinessDayIgnoresConnectionAndReturnsCanonicalStatus(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $oldConnectionId = '22222222-2222-4222-8222-222222222222';
        $this->seedActiveConnection($companyId, $oldConnectionId);
        $this->persistStatus($companyId, $oldConnectionId, '2026-01-09', FinancialReportSyncStatus::SUCCESS);

        $status = $this->repository->findByBusinessDay(
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            new \DateTimeImmutable('2026-01-09 00:00:00'),
        );

        self::assertNotNull($status);
        self::assertSame($oldConnectionId, $status->getConnectionId());
        self::assertSame('2026-01-09', $status->getBusinessDate()->format('Y-m-d'));
    }

    public function testRejectedClaimDoesNotMutateConnectionId(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $oldConnectionId = '22222222-2222-4222-8222-222222222222';
        $newConnectionId = '33333333-3333-4333-8333-333333333333';
        $this->seedActiveConnection($companyId, $oldConnectionId);
        $this->seedConnectionForExistingCompany($companyId, $newConnectionId);
        $this->persistStatus($companyId, $oldConnectionId, '2026-01-10', FinancialReportSyncStatus::PROCESSING);

        $claimed = $this->repository->claimForQueue(
            $newConnectionId,
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            'new-endpoint',
            new \DateTimeImmutable('2026-01-10 00:00:00'),
            FinancialReportSyncMode::REFRESH_14D,
            true,
            new \DateTimeImmutable('2026-01-10 12:00:00'),
        );
        $this->em->clear();

        $status = $this->repository->findByBusinessDay(
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            new \DateTimeImmutable('2026-01-10 00:00:00'),
        );

        self::assertNull($claimed);
        self::assertNotNull($status);
        self::assertSame($oldConnectionId, $status->getConnectionId());
        self::assertSame(FinancialReportSyncStatus::PROCESSING, $status->getStatus());
    }

    public function testRefreshClaimReusesDailySuccessBusinessStatus(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $oldConnectionId = '22222222-2222-4222-8222-222222222222';
        $newConnectionId = '33333333-3333-4333-8333-333333333333';
        $this->seedActiveConnection($companyId, $oldConnectionId);
        $this->seedConnectionForExistingCompany($companyId, $newConnectionId);
        $this->persistStatus($companyId, $oldConnectionId, '2026-01-11', FinancialReportSyncStatus::SUCCESS);

        $claimed = $this->repository->claimForQueue(
            $newConnectionId,
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            'new-endpoint',
            new \DateTimeImmutable('2026-01-11 00:00:00'),
            FinancialReportSyncMode::REFRESH_14D,
            true,
            new \DateTimeImmutable('2026-01-11 12:00:00'),
        );
        $this->em->flush();

        self::assertNotNull($claimed);
        self::assertSame($newConnectionId, $claimed->getConnectionId());
        self::assertSame(FinancialReportSyncMode::REFRESH_14D, $claimed->getMode());

        $count = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM marketplace_financial_report_sync_statuses WHERE company_id = :companyId AND marketplace = :marketplace AND report_type = :reportType AND business_date = :businessDate',
            [
                'companyId' => $companyId,
                'marketplace' => MarketplaceType::WILDBERRIES->value,
                'reportType' => self::REPORT_TYPE,
                'businessDate' => '2026-01-11',
            ],
        );
        self::assertSame(1, $count);
    }

    public function testFindOrCreateForDayReusesCanonicalBusinessStatusWithNewConnection(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $oldConnectionId = '22222222-2222-4222-8222-222222222222';
        $newConnectionId = '33333333-3333-4333-8333-333333333333';
        $this->seedActiveConnection($companyId, $oldConnectionId);
        $this->seedConnectionForExistingCompany($companyId, $newConnectionId);
        $this->persistStatus($companyId, $oldConnectionId, '2026-01-12', FinancialReportSyncStatus::SUCCESS);

        $status = $this->repository->findOrCreateForDay(
            $newConnectionId,
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            'new-endpoint',
            new \DateTimeImmutable('2026-01-12 00:00:00'),
        );
        $this->em->flush();

        self::assertSame($newConnectionId, $status->getConnectionId());
        self::assertSame('new-endpoint', $status->getApiEndpoint());

        $count = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM marketplace_financial_report_sync_statuses WHERE company_id = :companyId AND marketplace = :marketplace AND report_type = :reportType AND business_date = :businessDate',
            [
                'companyId' => $companyId,
                'marketplace' => MarketplaceType::WILDBERRIES->value,
                'reportType' => self::REPORT_TYPE,
                'businessDate' => '2026-01-12',
            ],
        );
        self::assertSame(1, $count);
    }

    public function testFindRetryDueDaysReturnsStaleRawLoadedAndProcessingButNotFreshYoungOrTerminal(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $connectionId = '22222222-2222-4222-8222-222222222222';
        $this->seedActiveConnection($companyId, $connectionId);

        $this->persistLoadedStatus($companyId, $connectionId, '2026-01-01', FinancialReportSyncStatus::PROCESSING);
        $this->persistLoadedStatus($companyId, $connectionId, '2026-01-02', FinancialReportSyncStatus::PROCESSING);
        $this->persistLoadedStatus($companyId, $connectionId, '2026-01-03', FinancialReportSyncStatus::PROCESSING);
        $this->persistLoadedStatus($companyId, $connectionId, '2026-01-04', FinancialReportSyncStatus::RAW_LOADED);
        $this->persistStatus($companyId, $connectionId, '2026-01-05', FinancialReportSyncStatus::SUCCESS);
        $this->persistStatus($companyId, $connectionId, '2026-01-06', FinancialReportSyncStatus::FAILED_FINAL);

        $this->backdateStatusUpdatedAt($companyId, '2026-01-01', '-7 hours');
        // 01-02 свежий; 01-03 старше порога QUEUED/LOADING (2 ч), но моложе порога обработки (6 ч).
        $this->backdateStatusUpdatedAt($companyId, '2026-01-03', '-3 hours');
        $this->backdateStatusUpdatedAt($companyId, '2026-01-04', '-7 hours');
        $this->backdateStatusUpdatedAt($companyId, '2026-01-05', '-48 hours');
        $this->backdateStatusUpdatedAt($companyId, '2026-01-06', '-48 hours');
        $this->em->clear();

        $days = $this->repository->findRetryDueDays(
            $companyId,
            $connectionId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-01-10 00:00:00'),
            new \DateTimeImmutable(),
            10,
        );

        self::assertSame(
            ['2026-01-01', '2026-01-04'],
            array_map(static fn (array $item): string => $item['business_date']->format('Y-m-d'), $days),
            'R-01: зависшие PROCESSING/RAW_LOADED попадают в recovery; свежие, моложе порога и терминальные — нет.',
        );
        self::assertSame(
            [FinancialReportSyncStatus::PROCESSING, FinancialReportSyncStatus::RAW_LOADED],
            array_map(static fn (array $item): ?FinancialReportSyncStatus => $item['status'], $days),
        );
    }

    public function testReclaimStaleProcessingTakesStaleDayExactlyOnceAndKeepsItProcessing(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $connectionId = '22222222-2222-4222-8222-222222222222';
        $this->seedActiveConnection($companyId, $connectionId);
        $this->persistLoadedStatus($companyId, $connectionId, '2026-01-01', FinancialReportSyncStatus::PROCESSING);
        $this->backdateStatusUpdatedAt($companyId, '2026-01-01', '-7 hours');
        $this->em->clear();

        $first = $this->reclaim($companyId, $connectionId, '2026-01-01');

        self::assertNotNull($first);
        self::assertTrue($first['reprocess']);
        self::assertSame(FinancialReportSyncStatus::PROCESSING, $first['previous_status']);
        self::assertSame(FinancialReportSyncStatus::PROCESSING, $first['status']->getStatus(), 'Новой state machine нет: день остаётся PROCESSING.');
        self::assertNotNull($first['status']->getRawDocumentId(), 'raw-документ сохраняется, повторная загрузка не нужна.');
        self::assertEqualsWithDelta(time() - 7 * 3600, $first['previous_updated_at']->getTimestamp(), 120, 'В лог/результат уходит прежний updatedAt (≈7 ч назад), а не обновлённый.');
        self::assertGreaterThan(time() - 60, $first['status']->getUpdatedAt()->getTimestamp());

        self::assertNull($this->reclaim($companyId, $connectionId, '2026-01-01'), 'Второй конкурирующий claim не забирает уже захваченный день.');

        $this->em->clear();
        $due = $this->repository->findRetryDueDays($companyId, $connectionId, MarketplaceType::WILDBERRIES, self::REPORT_TYPE, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-10'), new \DateTimeImmutable(), 10);
        self::assertSame([], $due, 'После захвата день снова свежий и не виден recovery-выборке.');
    }

    public function testReclaimStaleProcessingRefusesFreshYoungSuccessAndUnknownDay(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $connectionId = '22222222-2222-4222-8222-222222222222';
        $this->seedActiveConnection($companyId, $connectionId);
        $this->persistLoadedStatus($companyId, $connectionId, '2026-01-01', FinancialReportSyncStatus::PROCESSING);
        $this->persistLoadedStatus($companyId, $connectionId, '2026-01-02', FinancialReportSyncStatus::PROCESSING);
        $this->persistStatus($companyId, $connectionId, '2026-01-03', FinancialReportSyncStatus::SUCCESS);
        $this->backdateStatusUpdatedAt($companyId, '2026-01-02', '-3 hours');
        $this->backdateStatusUpdatedAt($companyId, '2026-01-03', '-48 hours');
        $this->em->clear();

        self::assertNull($this->reclaim($companyId, $connectionId, '2026-01-01'), 'Свежий PROCESSING не перехватывается.');
        self::assertNull($this->reclaim($companyId, $connectionId, '2026-01-02'), 'Моложе порога обработки (6 ч) — легитимно долгая обработка.');
        self::assertNull($this->reclaim($companyId, $connectionId, '2026-01-03'), 'SUCCESS никогда не возвращается в обработку по возрасту.');
        self::assertNull($this->reclaim($companyId, $connectionId, '2026-01-04'), 'Нет строки статуса — нечего захватывать.');

        $this->em->clear();
        $success = $this->repository->findByBusinessDay($companyId, MarketplaceType::WILDBERRIES, self::REPORT_TYPE, new \DateTimeImmutable('2026-01-03'));
        self::assertSame(FinancialReportSyncStatus::SUCCESS, $success?->getStatus());
    }

    public function testReclaimStaleProcessingIsScopedToCompany(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $connectionId = '22222222-2222-4222-8222-222222222222';
        $this->seedActiveConnection($companyId, $connectionId);
        $this->persistLoadedStatus($companyId, $connectionId, '2026-01-01', FinancialReportSyncStatus::PROCESSING);
        $this->backdateStatusUpdatedAt($companyId, '2026-01-01', '-7 hours');
        $this->em->clear();

        $otherCompanyId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $otherConnectionId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $this->seedActiveConnection($otherCompanyId, $otherConnectionId);

        self::assertNull($this->reclaim($otherCompanyId, $otherConnectionId, '2026-01-01'));
        self::assertNotNull($this->reclaim($companyId, $connectionId, '2026-01-01'));
    }

    public function testReclaimStaleProcessingWithoutRawDocumentFallsBackToQueuedResync(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $connectionId = '22222222-2222-4222-8222-222222222222';
        $this->seedActiveConnection($companyId, $connectionId);
        $this->persistStatus($companyId, $connectionId, '2026-01-01', FinancialReportSyncStatus::PROCESSING);
        $this->backdateStatusUpdatedAt($companyId, '2026-01-01', '-7 hours');
        $this->em->clear();

        $reclaim = $this->reclaim($companyId, $connectionId, '2026-01-01');

        self::assertNotNull($reclaim);
        self::assertFalse($reclaim['reprocess'], 'Нет raw-документа/режима — обрабатывать нечего, нужен обычный синк дня.');
        self::assertSame(FinancialReportSyncStatus::QUEUED, $reclaim['status']->getStatus());
        self::assertSame(FinancialReportSyncMode::MISSING, $reclaim['status']->getMode());
    }

    public function testClaimForQueueStillDoesNotOfferProcessingDays(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $connectionId = '22222222-2222-4222-8222-222222222222';
        $this->seedActiveConnection($companyId, $connectionId);
        $this->persistLoadedStatus($companyId, $connectionId, '2026-01-01', FinancialReportSyncStatus::PROCESSING);
        $this->backdateStatusUpdatedAt($companyId, '2026-01-01', '-48 hours');
        $this->em->clear();

        // Контракт claimForQueue() не менялся (Ozon realization использует его же со своей логикой):
        // восстановление идёт отдельным reclaimStaleProcessing(), а не через общий claim.
        self::assertNull($this->repository->claimForQueue(
            $connectionId,
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            'endpoint',
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            FinancialReportSyncMode::MISSING,
            true,
            new \DateTimeImmutable(),
        ));
    }

    /**
     * @return array{status: MarketplaceFinancialReportSyncStatus, previous_status: FinancialReportSyncStatus, previous_updated_at: \DateTimeImmutable, reprocess: bool}|null
     */
    private function reclaim(string $companyId, string $connectionId, string $day): ?array
    {
        return $this->repository->reclaimStaleProcessing(
            $connectionId,
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            'endpoint',
            new \DateTimeImmutable($day.' 00:00:00'),
            FinancialReportSyncMode::MISSING,
            new \DateTimeImmutable(),
        );
    }

    private function persistLoadedStatus(string $companyId, string $connectionId, string $day, FinancialReportSyncStatus $status): void
    {
        $entity = new MarketplaceFinancialReportSyncStatus(
            Uuid::uuid7()->toString(),
            $companyId,
            $connectionId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            'endpoint',
            new \DateTimeImmutable($day),
        );

        $entity->markLoading(FinancialReportSyncMode::DAILY);
        $entity->markRawLoaded(Uuid::uuid4()->toString(), 10, 'hash');
        if (FinancialReportSyncStatus::PROCESSING === $status) {
            $entity->markProcessing();
        }

        $this->repository->save($entity);
        $this->em->flush();
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
            FinancialReportSyncStatus::AUTH_FAILED => $entity->markAuthFailed('TestException', 'auth failed', 401, null),
            FinancialReportSyncStatus::FAILED_FINAL => $entity->markFailedFinal('TestException', 'failed final', 500, null),
            FinancialReportSyncStatus::CONFLICT => $entity->markConflict('TestException', 'conflict', 409, null),
            default => null,
        };

        $this->repository->save($entity);
        $this->em->flush();
    }

    private function backdateStatusUpdatedAt(string $companyId, string $day, string $modify = '-3 hours'): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE marketplace_financial_report_sync_statuses SET updated_at = :ts WHERE company_id = :companyId AND business_date = :day',
            [
                'ts' => (new \DateTimeImmutable($modify))->format('Y-m-d H:i:s'),
                'companyId' => $companyId,
                'day' => $day,
            ],
        );
    }

    private function seedConnectionForExistingCompany(string $companyId, string $connectionId): void
    {
        // uniq_company_marketplace_type допускает один WB seller-кабинет на компанию:
        // тесты «нового подключения» моделируют замену кабинета, поэтому старую строку убираем.
        $this->em->getConnection()->executeStatement(
            "DELETE FROM marketplace_connections WHERE company_id = :companyId AND marketplace = 'wildberries' AND connection_type = 'seller'",
            ['companyId' => $companyId],
        );

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

    private function seedActiveConnection(string $companyId, string $connectionId): void
    {
        $existing = $this->em->find(Company::class, $companyId);
        if (!$existing instanceof Company) {
            $ownerId = Uuid::uuid7()->toString();
            $owner = UserBuilder::aUser()
                ->withId($ownerId)
                ->withEmail(sprintf('owner+%s@example.test', $ownerId))
                ->build();
            $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
            $this->em->persist($owner);
            $this->em->persist($company);
            $this->em->flush();
        }

        $this->seedConnectionForExistingCompany($companyId, $connectionId);
    }
}
