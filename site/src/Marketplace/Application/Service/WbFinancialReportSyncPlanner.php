<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Infrastructure\Query\ActiveWbConnectionsQuery;
use App\Marketplace\Message\SyncWbFinancialReportDayMessage;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use DateTimeImmutable;
use Symfony\Component\Messenger\MessageBusInterface;

final class WbFinancialReportSyncPlanner
{
    private const REPORT_TYPE = 'sales_report';

    public function __construct(
        private readonly ActiveWbConnectionsQuery $activeWbConnectionsQuery,
        private readonly WbFinancialReportPeriodResolver $periodResolver,
        private readonly MarketplaceFinancialReportSyncStatusRepository $syncStatusRepository,
        private readonly MessageBusInterface $messageBus,
    ) {}

    public function planDaily(?string $companyId = null, ?string $connectionId = null, bool $force = false): int
    {
        $day = $this->periodResolver->yesterday();

        return $this->planForDays(
            $this->activeWbConnectionsQuery->execute($companyId, $connectionId),
            [$day],
            FinancialReportSyncMode::DAILY,
            $force,
            static function (?FinancialReportSyncStatus $status) use ($force): bool {
                if (\in_array($status, [FinancialReportSyncStatus::LOADING, FinancialReportSyncStatus::PROCESSING], true)) {
                    return false;
                }

                if ($force) {
                    return true;
                }

                return null === $status
                    || !\in_array($status, [FinancialReportSyncStatus::SUCCESS, FinancialReportSyncStatus::EMPTY], true);
            },
        );
    }

    public function planRefresh14Days(?string $companyId = null, ?string $connectionId = null): int
    {
        return $this->planForDays(
            $this->activeWbConnectionsQuery->execute($companyId, $connectionId),
            $this->periodResolver->last14Days(),
            FinancialReportSyncMode::REFRESH_14D,
            true,
            static fn (?FinancialReportSyncStatus $status): bool => !\in_array($status, [
                FinancialReportSyncStatus::LOADING,
                FinancialReportSyncStatus::PROCESSING,
            ], true),
        );
    }

    public function planMissing(?string $companyId = null, ?string $connectionId = null, int $maxDays = 14): int
    {
        if ($maxDays <= 0) {
            return 0;
        }

        $dispatched = 0;
        $days = $this->periodResolver->daysBetween($this->periodResolver->currentYearStart(), $this->periodResolver->yesterday());

        foreach ($this->activeWbConnectionsQuery->execute($companyId, $connectionId) as $connection) {
            $scheduledDays = $this->syncStatusRepository->findMissingOrRetryDueDays(
                $connection['connection_id'],
                $connection['company_id'],
                self::REPORT_TYPE,
                $days,
                $maxDays,
            );

            foreach ($scheduledDays as $day) {
                $this->dispatch($connection['company_id'], $connection['connection_id'], $day, FinancialReportSyncMode::MISSING, false);
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
            static fn (?FinancialReportSyncStatus $status): bool => null === $status
                || FinancialReportSyncStatus::FAILED === $status,
        );
    }

    /** @param list<array{id: string, company_id: string, connection_id: string}> $connections
     * @param list<DateTimeImmutable> $days
     * @param callable(?FinancialReportSyncStatus): bool $shouldDispatch
     */
    private function planForDays(array $connections, array $days, FinancialReportSyncMode $mode, bool $forceRefresh, callable $shouldDispatch): int
    {
        $dispatched = 0;

        foreach ($connections as $connection) {
            foreach ($days as $day) {
                $status = $this->syncStatusRepository->findStatusEnumByDay(
                    $connection['connection_id'],
                    $connection['company_id'],
                    $day,
                    self::REPORT_TYPE,
                );

                if (!$shouldDispatch($status)) {
                    continue;
                }

                $this->dispatch($connection['company_id'], $connection['connection_id'], $day, $mode, $forceRefresh);
                ++$dispatched;
            }
        }

        return $dispatched;
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
