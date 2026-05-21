<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Infrastructure\Api\Wildberries\WbFinanceSalesReportClient;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;

final class WbFinancialReportFirstAvailableResolver
{
    public const PHASE_FULL_RANGE = 'full_range';
    public const PHASE_MONTH_SCAN = 'month_scan';
    public const PHASE_DAY_SCAN = 'day_scan';

    private const REPORT_TYPE = 'sales_report';
    private const FLOOR_DATE = '2024-01-29';

    public function __construct(
        private readonly WbFinancialReportPeriodResolver $periodResolver,
        private readonly MarketplaceFinancialReportSyncStatusRepository $syncStatusRepository,
        private readonly WbFinanceSalesReportClient $salesReportClient,
    ) {}

    public function resolve(
        string $connectionId,
        string $companyId,
        string $apiKey,
        ?string $phase = null,
        ?\DateTimeImmutable $probeFrom = null,
        ?\DateTimeImmutable $probeTo = null,
    ): WbFinancialReportFirstAvailableResolverResult {
        $yearStart = $this->periodResolver->currentYearStart();
        $yesterday = $this->periodResolver->yesterday();

        if ($yearStart > $yesterday) {
            return WbFinancialReportFirstAvailableResolverResult::noData();
        }

        if (null === $phase) {
            $localFirstDate = $this->findLocalFirstKnownDataDateInRange($connectionId, $companyId, $yearStart, $yesterday);
            if (null !== $localFirstDate) {
                return WbFinancialReportFirstAvailableResolverResult::withStartDate($localFirstDate);
            }

            try {
                if (!$this->salesReportClient->hasAnyDataForConnection($connectionId, $apiKey, $yearStart->format('Y-m-d'), $yesterday->format('Y-m-d'))) {
                    return WbFinancialReportFirstAvailableResolverResult::noData();
                }
            } catch (MarketplaceRateLimitException $e) {
                return WbFinancialReportFirstAvailableResolverResult::incomplete(self::PHASE_FULL_RANGE, $yearStart, $yesterday, $e->getRetryAfter());
            }

            return $this->firstMonthIncomplete($yearStart, $yesterday);
        }

        if (self::PHASE_FULL_RANGE === $phase) {
            return $this->continueFullRangeProbe($connectionId, $apiKey, $probeFrom, $probeTo, $yearStart, $yesterday);
        }

        if (self::PHASE_MONTH_SCAN === $phase) {
            return $this->continueMonthScan($connectionId, $apiKey, $probeFrom, $probeTo, $yesterday);
        }

        if (self::PHASE_DAY_SCAN === $phase) {
            return $this->continueDayScan($connectionId, $apiKey, $probeFrom, $probeTo, $yesterday);
        }

        return WbFinancialReportFirstAvailableResolverResult::noData();
    }

    private function continueFullRangeProbe(string $connectionId, string $apiKey, ?\DateTimeImmutable $probeFrom, ?\DateTimeImmutable $probeTo, \DateTimeImmutable $yearStart, \DateTimeImmutable $yesterday): WbFinancialReportFirstAvailableResolverResult
    {
        if (!$probeFrom instanceof \DateTimeImmutable || !$probeTo instanceof \DateTimeImmutable || $probeFrom > $probeTo || $probeFrom > $yesterday) {
            return WbFinancialReportFirstAvailableResolverResult::noData();
        }

        try {
            $hasAnyData = $this->salesReportClient->hasAnyDataForConnection($connectionId, $apiKey, $probeFrom->format('Y-m-d'), $probeTo->format('Y-m-d'));
        } catch (MarketplaceRateLimitException $e) {
            return WbFinancialReportFirstAvailableResolverResult::incomplete(self::PHASE_FULL_RANGE, $probeFrom, $probeTo, $e->getRetryAfter());
        }

        if (!$hasAnyData) {
            return WbFinancialReportFirstAvailableResolverResult::noData();
        }

        return $this->firstMonthIncomplete($probeFrom >= $yearStart ? $probeFrom : $yearStart, $yesterday);
    }

    private function continueMonthScan(string $connectionId, string $apiKey, ?\DateTimeImmutable $monthFrom, ?\DateTimeImmutable $monthTo, \DateTimeImmutable $yesterday): WbFinancialReportFirstAvailableResolverResult
    {
        if (!$monthFrom instanceof \DateTimeImmutable || !$monthTo instanceof \DateTimeImmutable || $monthFrom > $yesterday) {
            return WbFinancialReportFirstAvailableResolverResult::noData();
        }

        try {
            $hasMonthData = $this->salesReportClient->hasAnyDataForConnection($connectionId, $apiKey, $monthFrom->format('Y-m-d'), $monthTo->format('Y-m-d'));
        } catch (MarketplaceRateLimitException $e) {
            return WbFinancialReportFirstAvailableResolverResult::incomplete(self::PHASE_MONTH_SCAN, $monthFrom, $monthTo, $e->getRetryAfter());
        }

        if ($hasMonthData) {
            return WbFinancialReportFirstAvailableResolverResult::incomplete(self::PHASE_DAY_SCAN, $monthFrom, $monthTo);
        }

        $nextMonth = $monthFrom->modify('first day of next month')->setTime(0, 0, 0);
        if ($nextMonth > $yesterday) {
            return WbFinancialReportFirstAvailableResolverResult::noData();
        }

        $nextMonthEnd = $nextMonth->modify('last day of this month');
        if ($nextMonthEnd > $yesterday) {
            $nextMonthEnd = $yesterday;
        }

        return WbFinancialReportFirstAvailableResolverResult::incomplete(self::PHASE_MONTH_SCAN, $nextMonth, $nextMonthEnd);
    }

    private function continueDayScan(string $connectionId, string $apiKey, ?\DateTimeImmutable $dayFrom, ?\DateTimeImmutable $dayTo, \DateTimeImmutable $yesterday): WbFinancialReportFirstAvailableResolverResult
    {
        if (!$dayFrom instanceof \DateTimeImmutable || !$dayTo instanceof \DateTimeImmutable || $dayFrom > $dayTo || $dayFrom > $yesterday) {
            return WbFinancialReportFirstAvailableResolverResult::noData();
        }

        try {
            $hasDayData = $this->salesReportClient->hasAnyDataForConnection($connectionId, $apiKey, $dayFrom->format('Y-m-d'), $dayFrom->format('Y-m-d'));
        } catch (MarketplaceRateLimitException $e) {
            return WbFinancialReportFirstAvailableResolverResult::incomplete(self::PHASE_DAY_SCAN, $dayFrom, $dayTo, $e->getRetryAfter());
        }

        if ($hasDayData) {
            return WbFinancialReportFirstAvailableResolverResult::withStartDate($dayFrom);
        }

        $nextDay = $dayFrom->modify('+1 day')->setTime(0, 0, 0);
        if ($nextDay > $dayTo || $nextDay > $yesterday) {
            return WbFinancialReportFirstAvailableResolverResult::noData();
        }

        return WbFinancialReportFirstAvailableResolverResult::incomplete(self::PHASE_DAY_SCAN, $nextDay, $dayTo);
    }

    private function firstMonthIncomplete(\DateTimeImmutable $yearStart, \DateTimeImmutable $yesterday): WbFinancialReportFirstAvailableResolverResult
    {
        $floor = new \DateTimeImmutable(self::FLOOR_DATE);
        $monthFrom = $yearStart >= $floor ? $yearStart : $floor;
        if ($monthFrom > $yesterday) {
            return WbFinancialReportFirstAvailableResolverResult::noData();
        }

        $monthTo = $monthFrom->modify('last day of this month');
        if ($monthTo > $yesterday) {
            $monthTo = $yesterday;
        }

        return WbFinancialReportFirstAvailableResolverResult::incomplete(self::PHASE_MONTH_SCAN, $monthFrom, $monthTo);
    }

    private function findLocalFirstKnownDataDateInRange(string $connectionId, string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): ?\DateTimeImmutable
    {
        $positiveStatuses = [FinancialReportSyncStatus::SUCCESS, FinancialReportSyncStatus::RAW_LOADED, FinancialReportSyncStatus::PROCESSING];

        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')->setTime(0, 0, 0)) {
            $status = $this->syncStatusRepository->findStatusEnumByDay($connectionId, $companyId, $day, self::REPORT_TYPE);
            if (\in_array($status, $positiveStatuses, true)) {
                return $day;
            }
        }

        return null;
    }
}
