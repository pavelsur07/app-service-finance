<?php

declare(strict_types=1);

namespace App\Ai\Dto;

/**
 * @psalm-type TotalsByCategory = array<string, array{inflow: float, outflow: float}>
 * @psalm-type DailyBalances = array<string, float>
 * @psalm-type UpcomingPayment = array{dueDate: string, amount: float, description: string, counterparty?: string}
 */
final class CashflowAgentInput
{
    /**
     * @param TotalsByCategory $totalsByCategory
     * @param DailyBalances $dailyBalances
     * @param list<UpcomingPayment> $upcomingPayments
     */
    public function __construct(
        public readonly array $totalsByCategory,
        public readonly array $dailyBalances,
        public readonly array $upcomingPayments,
        public readonly float $averageMonthlyRevenue,
        public readonly float $averageMonthlyExpenses,
    ) {
    }
}
