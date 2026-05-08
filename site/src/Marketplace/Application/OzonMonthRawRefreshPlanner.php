<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\DTO\OzonMonthRawRefreshPlanItem;
use App\Marketplace\Infrastructure\Query\ActiveOzonConnectionsQuery;

final readonly class OzonMonthRawRefreshPlanner
{
    public function __construct(
        private ActiveOzonConnectionsQuery $connectionsQuery,
    ) {
    }

    /**
     * @return list<OzonMonthRawRefreshPlanItem>
     */
    public function plan(
        int $year,
        int $month,
        ?string $companyId = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): array {
        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $monthEnd = $monthStart->modify('last day of this month');

        $rangeStart = $from ? $this->maxDate($from->setTime(0, 0), $monthStart) : $monthStart;
        $rangeEnd = $to ? $this->minDate($to->setTime(0, 0), $monthEnd) : $monthEnd;

        $today = new \DateTimeImmutable('today');
        if ($monthStart->format('Y-m') === $today->format('Y-m')) {
            $yesterday = $today->modify('-1 day');
            $rangeEnd = $this->minDate($rangeEnd, $yesterday);
        }

        if ($rangeStart > $rangeEnd) {
            return [];
        }

        $connections = $this->connectionsQuery->execute($companyId);
        if ([] === $connections) {
            return [];
        }

        $plan = [];

        foreach ($connections as $connection) {
            $lockBefore = isset($connection['finance_lock_before']) && null !== $connection['finance_lock_before']
                ? new \DateTimeImmutable((string) $connection['finance_lock_before'])
                : null;

            for ($cursor = $rangeStart; $cursor <= $rangeEnd; $cursor = $cursor->modify('+1 day')) {
                $status = 'planned';
                $reason = null;

                if (null !== $lockBefore && $cursor <= $lockBefore) {
                    $status = 'skipped';
                    $reason = 'finance_locked';
                }

                $plan[] = new OzonMonthRawRefreshPlanItem(
                    companyId: (string) $connection['company_id'],
                    connectionId: (string) $connection['id'],
                    marketplace: 'ozon',
                    date: $cursor->format('Y-m-d'),
                    status: $status,
                    skippedReason: $reason,
                );
            }
        }

        return $plan;
    }

    private function minDate(\DateTimeImmutable $a, \DateTimeImmutable $b): \DateTimeImmutable
    {
        return $a <= $b ? $a : $b;
    }

    private function maxDate(\DateTimeImmutable $a, \DateTimeImmutable $b): \DateTimeImmutable
    {
        return $a >= $b ? $a : $b;
    }
}
