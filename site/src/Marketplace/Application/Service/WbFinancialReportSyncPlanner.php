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
        if ($maxDays <= 0) {
            return 0;
        }

        $dispatched = 0;
        $days = $this->periodResolver->last14Days();
        $from = $days[0];
        $to = $days[array_key_last($days)];
        $now = $this->clock->now();

        foreach ($this->activeWbConnectionsQuery->execute($companyId, $connectionId) as $connection) {
            $statuses = $this->syncStatusRepository->findStatusesForDateRange(
                $connection['company_id'],
                $connection['connection_id'],
                self::REPORT_TYPE,
                $from,
                $to,
            );
            $statusByDay = [];
            foreach ($statuses as $statusEntity) {
                $statusByDay[$statusEntity->getBusinessDate()->format('Y-m-d')] = $statusEntity;
            }

            $retryDue = [];
            $success = [];
            $unknown = [];

            foreach ($days as $day) {
                $statusEntity = $statusByDay[$day->format('Y-m-d')] ?? null;
                $status = $statusEntity?->getStatus();

                if (\in_array($status, [FinancialReportSyncStatus::LOADING, FinancialReportSyncStatus::PROCESSING], true)) {
                    continue;
                }

                if (FinancialReportSyncStatus::FAILED === $status) {
                    if (null !== $statusEntity?->getNextRetryAt() && $statusEntity->getNextRetryAt() > $now) {
                        continue;
                    }

                    $retryDue[] = $day;
                    continue;
                }

                if (\in_array($status, [FinancialReportSyncStatus::SUCCESS, FinancialReportSyncStatus::EMPTY], true)) {
                    $updatedAt = $statusEntity?->getUpdatedAt();
                    if (null === $updatedAt) {
                        $updatedAt = new DateTimeImmutable('9999-12-31T00:00:00+00:00');
                    }

                    $success[] = [
                        'day' => $day,
                        'updated_at' => $updatedAt,
                    ];
                    continue;
                }

                if (null === $status) {
                    $unknown[] = $day;
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
            foreach (array_merge($retryDue, $successDays, $unknown) as $day) {
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

    public function planMissing(?string $companyId = null, ?string $connectionId = null, int $maxDays = 14): int
    {
        if ($maxDays <= 0) {
            return 0;
        }

        $dispatched = 0;
        $from = $this->periodResolver->currentYearStart();
        $to = $this->periodResolver->yesterday();
        $allDays = $this->periodResolver->daysBetween($from, $to);
        $now = $this->clock->now();

        foreach ($this->activeWbConnectionsQuery->execute($companyId, $connectionId) as $connection) {
            $statuses = $this->syncStatusRepository->findStatusesForDateRange(
                $connection['company_id'],
                $connection['connection_id'],
                self::REPORT_TYPE,
                $from,
                $to,
            );

            $knownDays = [];
            foreach ($statuses as $status) {
                $knownDays[$status->getBusinessDate()->format('Y-m-d')] = true;
            }

            $retryDueDays = $this->syncStatusRepository->findRetryDueDays(
                $connection['company_id'],
                $connection['connection_id'],
                self::REPORT_TYPE,
                $from,
                $to,
                $now,
                $maxDays,
            );

            $scheduledDays = [];
            foreach ($retryDueDays as $day) {
                $scheduledDays[$day->format('Y-m-d')] = $day;
            }

            if (count($scheduledDays) < $maxDays) {
                foreach ($allDays as $day) {
                    $dayKey = $day->format('Y-m-d');
                    if (isset($knownDays[$dayKey]) || isset($scheduledDays[$dayKey])) {
                        continue;
                    }

                    $scheduledDays[$dayKey] = $day;
                    if (count($scheduledDays) >= $maxDays) {
                        break;
                    }
                }
            }

            ksort($scheduledDays);
            foreach ($scheduledDays as $day) {
                if (!$this->claimAndDispatch($connection['company_id'], $connection['connection_id'], $day, FinancialReportSyncMode::MISSING, false)) {
                    continue;
                }

                ++$dispatched;
            }
        }

        return $dispatched;
    }

    public function planInitial(?string $companyId = null, ?string $connectionId = null, ?DateTimeImmutable $startFrom = null): int
    {
        $start = $startFrom ?? $this->periodResolver->currentYearStart();
        $days = $this->periodResolver->daysBetween($start, $this->periodResolver->yesterday());

        return $this->planForDays(
            $this->activeWbConnectionsQuery->execute($companyId, $connectionId),
            $days,
            FinancialReportSyncMode::INITIAL,
            false,
        );
    }

    /** @param list<array{id: string, company_id: string, connection_id: string}> $connections
     * @param list<DateTimeImmutable> $days
     */
    private function planForDays(array $connections, array $days, FinancialReportSyncMode $mode, bool $forceRefresh): int
    {
        $dispatched = 0;

        foreach ($connections as $connection) {
            foreach ($days as $day) {
                if (!$this->claimAndDispatch($connection['company_id'], $connection['connection_id'], $day, $mode, $forceRefresh)) {
                    continue;
                }

                ++$dispatched;
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

        $this->dispatch($companyId, $connectionId, $day, $mode, $forceRefresh);

        return true;
    }

    private function dispatch(string $companyId, string $connectionId, DateTimeImmutable $day, FinancialReportSyncMode $mode, bool $forceRefresh): void
    {
        $this->messageBus->dispatch(new SyncWbFinancialReportDayMessage(
            $companyId,
            $connectionId,
            $day->format('Y-m-d'),
            $mode->value,
            $forceRefresh,
        ));
    }
}
