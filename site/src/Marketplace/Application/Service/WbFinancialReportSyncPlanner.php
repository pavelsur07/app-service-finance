<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\ActiveWbConnectionsQuery;
use App\Marketplace\Message\SyncWbFinancialReportDayMessage;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusLookupInterface;
use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class WbFinancialReportSyncPlanner implements WbFinancialReportSyncPlannerInterface
{
    private const REPORT_TYPE = 'sales_report';
    private const API_ENDPOINT = 'wildberries::finance-sales-reports-detailed';

    public function __construct(
        private readonly ActiveWbConnectionsQuery $activeWbConnectionsQuery,
        private readonly WbFinancialReportPeriodResolver $periodResolver,
        private readonly MarketplaceFinancialReportSyncStatusLookupInterface $syncStatusRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockInterface $clock,
    ) {}

    public function planDaily(?string $companyId = null, ?string $connectionId = null, bool $force = false): int
    {
        $day = $this->periodResolver->yesterday();

        return $this->planForDays(
            $this->activeWbConnectionsQuery->execute($companyId, $connectionId),
            [$day],
            FinancialReportSyncMode::DAILY,
            $force,
        );
    }

    public function planRefresh14Days(?string $companyId = null, ?string $connectionId = null, int $maxDays = 1): int
    {
        return $this->planRefreshRecentDays($companyId, $connectionId, 14, $maxDays);
    }

    public function planRefreshRecentDays(?string $companyId = null, ?string $connectionId = null, int $daysBack = 2, int $maxDays = 1): int
    {
        if ($maxDays <= 0 || $daysBack <= 0) {
            return 0;
        }

        $dispatched = 0;
        $days = $this->periodResolver->lastDays($daysBack);
        $from = $days[0];
        $to = $days[array_key_last($days)];
        $now = $this->clock->now();

        foreach ($this->activeWbConnectionsQuery->execute($companyId, $connectionId) as $connection) {
            $statuses = $this->syncStatusRepository->findStatusesForDateRange(
                $connection['company_id'],
                $connection['connection_id'],
                MarketplaceType::WILDBERRIES,
                self::REPORT_TYPE,
                $from,
                $to,
            );
            $statusByDayAndMode = [];
            foreach ($statuses as $statusEntity) {
                $mode = $statusEntity->getMode();
                if (null === $mode) {
                    continue;
                }

                $statusByDayAndMode[$statusEntity->getBusinessDate()->format('Y-m-d')][$mode->value] = $statusEntity;
            }

            $retryDue = [];
            $success = [];
            $dailyCompletedWithoutRefresh = [];

            foreach ($days as $day) {
                $dayKey = $day->format('Y-m-d');
                $dailyStatus = ($statusByDayAndMode[$dayKey][FinancialReportSyncMode::DAILY->value] ?? null)?->getStatus();
                $dailyCompleted = \in_array($dailyStatus, [FinancialReportSyncStatus::SUCCESS, FinancialReportSyncStatus::EMPTY], true);
                $refreshStatusEntity = $statusByDayAndMode[$dayKey][FinancialReportSyncMode::REFRESH_14D->value] ?? null;
                $refreshStatus = $refreshStatusEntity?->getStatus();

                if (null !== $refreshStatusEntity) {
                    if (\in_array($refreshStatus, [FinancialReportSyncStatus::LOADING, FinancialReportSyncStatus::PROCESSING], true)) {
                        continue;
                    }

                    if (\in_array($refreshStatus, [FinancialReportSyncStatus::QUEUED, FinancialReportSyncStatus::FAILED], true)) {
                        if (null !== $refreshStatusEntity->getNextRetryAt() && $refreshStatusEntity->getNextRetryAt() > $now) {
                            continue;
                        }

                        if (FinancialReportSyncStatus::QUEUED === $refreshStatus && null === $refreshStatusEntity->getNextRetryAt()) {
                            continue;
                        }

                        $retryDue[] = $day;
                        continue;
                    }

                    if (\in_array($refreshStatus, [FinancialReportSyncStatus::SUCCESS, FinancialReportSyncStatus::EMPTY], true)) {
                        if ($dailyCompleted) {
                            $success[] = [
                                'day' => $day,
                                'updated_at' => $refreshStatusEntity->getUpdatedAt() ?? new DateTimeImmutable('9999-12-31T00:00:00+00:00'),
                            ];
                        }

                        continue;
                    }
                }

                if ($dailyCompleted) {
                    $dailyCompletedWithoutRefresh[] = $day;
                }
            }

            usort($success, static function (array $a, array $b): int {
                $timestampCompare = $a['updated_at']->getTimestamp() <=> $b['updated_at']->getTimestamp();
                if (0 !== $timestampCompare) {
                    return $timestampCompare;
                }

                return $a['day']->getTimestamp() <=> $b['day']->getTimestamp();
            });

            $successDays = array_map(static fn (array $item): DateTimeImmutable => $item['day'], $success);

            $scheduledForConnection = 0;
            foreach (array_merge($retryDue, $successDays, $dailyCompletedWithoutRefresh) as $day) {
                if ($scheduledForConnection >= $maxDays) {
                    break;
                }

                if (!$this->claimAndDispatch($connection['company_id'], $connection['connection_id'], $day, FinancialReportSyncMode::REFRESH_14D, true)) {
                    continue;
                }

                ++$dispatched;
                ++$scheduledForConnection;
            }
        }

        return $dispatched;
    }

    public function planDueRetry(?string $companyId = null, ?string $connectionId = null, int $maxDays = 1, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): int
    {
        if ($maxDays <= 0) {
            return 0;
        }

        $dispatched = 0;
        $from ??= $this->periodResolver->currentYearStart();
        $to ??= $this->periodResolver->yesterday();
        $now = $this->clock->now();

        foreach ($this->activeWbConnectionsQuery->execute($companyId, $connectionId) as $connection) {
            $retryItems = $this->syncStatusRepository->findRetryDueDays(
                $connection['company_id'],
                $connection['connection_id'],
                MarketplaceType::WILDBERRIES,
                self::REPORT_TYPE,
                $from,
                $to,
                $now,
                $maxDays,
            );

            $scheduledForConnection = 0;
            foreach ($retryItems as $retryItem) {
                if ($scheduledForConnection >= $maxDays) {
                    break;
                }

                if (!$this->claimAndDispatch(
                    $connection['company_id'],
                    $connection['connection_id'],
                    $retryItem['business_date'],
                    $retryItem['mode'],
                    false,
                )) {
                    continue;
                }

                ++$dispatched;
                ++$scheduledForConnection;
            }
        }

        return $dispatched;
    }


    public function planRange(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        FinancialReportSyncMode $mode,
        ?string $companyId = null,
        ?string $connectionId = null,
        bool $forceRefresh = false,
    ): int {
        return $this->planForDays(
            $this->activeWbConnectionsQuery->execute($companyId, $connectionId),
            $this->periodResolver->daysBetween($from, $to),
            $mode,
            $forceRefresh,
        );
    }

    public function planMissing(?string $companyId = null, ?string $connectionId = null, int $maxDays = 14, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): int
    {
        if ($maxDays <= 0) {
            return 0;
        }

        $dispatched = 0;
        $from ??= $this->periodResolver->currentYearStart();
        $to ??= $this->periodResolver->yesterday();
        $allDays = $this->periodResolver->daysBetween($from, $to);
        $now = $this->clock->now();

        foreach ($this->activeWbConnectionsQuery->execute($companyId, $connectionId) as $connection) {
            $statuses = $this->syncStatusRepository->findStatusesForDateRange(
                $connection['company_id'],
                $connection['connection_id'],
                MarketplaceType::WILDBERRIES,
                self::REPORT_TYPE,
                $from,
                $to,
            );

            $knownDays = [];
            foreach ($statuses as $status) {
                $knownDays[$status->getBusinessDate()->format('Y-m-d')] = true;
            }

            $retryDueItems = $this->syncStatusRepository->findRetryDueDays(
                $connection['company_id'],
                $connection['connection_id'],
                MarketplaceType::WILDBERRIES,
                self::REPORT_TYPE,
                $from,
                $to,
                $now,
                $maxDays,
            );

            $scheduledDays = [];
            foreach ($retryDueItems as $retryItem) {
                $day = $retryItem['business_date'];
                $scheduledDays[$day->format('Y-m-d')] = $retryItem;
            }

            if (count($scheduledDays) < $maxDays) {
                foreach ($allDays as $day) {
                    $dayKey = $day->format('Y-m-d');
                    if (isset($knownDays[$dayKey]) || isset($scheduledDays[$dayKey])) {
                        continue;
                    }

                    $scheduledDays[$dayKey] = ['business_date' => $day, 'mode' => FinancialReportSyncMode::MISSING];
                    if (count($scheduledDays) >= $maxDays) {
                        break;
                    }
                }
            }

            ksort($scheduledDays);
            foreach ($scheduledDays as $retryItem) {
                if (!$this->claimAndDispatch(
                    $connection['company_id'],
                    $connection['connection_id'],
                    $retryItem['business_date'],
                    $retryItem['mode'],
                    false,
                )) {
                    continue;
                }

                ++$dispatched;
            }
        }

        return $dispatched;
    }

    public function planInitial(?string $companyId = null, ?string $connectionId = null, ?DateTimeImmutable $startFrom = null, int $maxDays = 1): int
    {
        if ($maxDays <= 0) {
            return 0;
        }

        $start = $startFrom ?? $this->periodResolver->currentYearStart();

        return $this->planForDays(
            $this->activeWbConnectionsQuery->execute($companyId, $connectionId),
            $this->periodResolver->daysBetween($start, $this->periodResolver->yesterday()),
            FinancialReportSyncMode::INITIAL,
            false,
            $maxDays,
        );
    }

    /** @param list<array{id: string, company_id: string, connection_id: string}> $connections
     * @param list<DateTimeImmutable> $days
     */
    private function planForDays(
        array $connections,
        array $days,
        FinancialReportSyncMode $mode,
        bool $forceRefresh,
        ?int $maxDaysPerConnection = null,
    ): int
    {
        $dispatched = 0;

        foreach ($connections as $connection) {
            $scheduledForConnection = 0;

            foreach ($days as $day) {
                if (null !== $maxDaysPerConnection && $scheduledForConnection >= $maxDaysPerConnection) {
                    break;
                }

                if (!$this->claimAndDispatch($connection['company_id'], $connection['connection_id'], $day, $mode, $forceRefresh)) {
                    continue;
                }

                ++$dispatched;
                ++$scheduledForConnection;
            }
        }

        return $dispatched;
    }

    private function claimAndDispatch(string $companyId, string $connectionId, DateTimeImmutable $day, FinancialReportSyncMode $mode, bool $forceRefresh): bool
    {
        $status = $this->syncStatusRepository->claimForQueue(
            $connectionId,
            $companyId,
            MarketplaceType::WILDBERRIES,
            self::REPORT_TYPE,
            self::API_ENDPOINT,
            $day,
            $mode,
            $forceRefresh,
            $this->clock->now(),
        );

        if (null === $status) {
            return false;
        }

        $this->dispatch($companyId, $connectionId, $day, $mode, $forceRefresh, $status->getNextRrdId() ?? 0, $status->getStagingRawDocumentId());

        return true;
    }

    private function dispatch(string $companyId, string $connectionId, DateTimeImmutable $day, FinancialReportSyncMode $mode, bool $forceRefresh, int $rrdId = 0, ?string $rawDocumentId = null): void
    {
        $this->messageBus->dispatch(new SyncWbFinancialReportDayMessage(
            $companyId,
            $connectionId,
            $day->format('Y-m-d'),
            $mode->value,
            $forceRefresh,
            $rrdId,
            $rawDocumentId,
        ));
    }
}
