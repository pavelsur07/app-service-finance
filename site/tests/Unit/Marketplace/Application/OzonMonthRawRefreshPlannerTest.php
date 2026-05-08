<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application;

use App\Marketplace\Application\OzonMonthRawRefreshPlanner;
use App\Marketplace\Infrastructure\Query\ActiveOzonConnectionsQuery;
use PHPUnit\Framework\TestCase;

final class OzonMonthRawRefreshPlannerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';
    private const CONNECTION_ID = '22222222-2222-2222-2222-000000000001';

    public function testBuildsFullPlanForPreviousMonth(): void
    {
        $today = new \DateTimeImmutable('today');
        $previousMonth = $today->modify('first day of previous month');

        $query = $this->createMock(ActiveOzonConnectionsQuery::class);
        $query->expects(self::once())->method('execute')->with(null)->willReturn([
            ['id' => self::CONNECTION_ID, 'company_id' => self::COMPANY_ID, 'finance_lock_before' => null],
        ]);

        $planner = new OzonMonthRawRefreshPlanner($query);
        $plan = $planner->plan((int) $previousMonth->format('Y'), (int) $previousMonth->format('m'));

        $expectedDays = (int) $previousMonth->format('t');
        self::assertCount($expectedDays, $plan);
        self::assertSame($previousMonth->format('Y-m-01'), $plan[0]->date);
        self::assertSame($previousMonth->format('Y-m-t'), $plan[$expectedDays - 1]->date);
    }

    public function testCurrentMonthExcludesTodayAndFutureDates(): void
    {
        $today = new \DateTimeImmutable('today');
        $dayOfMonth = (int) $today->format('j');

        $query = $this->createMock(ActiveOzonConnectionsQuery::class);
        $query->method('execute')->willReturn([
            ['id' => self::CONNECTION_ID, 'company_id' => self::COMPANY_ID, 'finance_lock_before' => null],
        ]);

        $planner = new OzonMonthRawRefreshPlanner($query);
        $plan = $planner->plan((int) $today->format('Y'), (int) $today->format('m'));

        $dates = array_map(static fn ($item) => $item->date, $plan);

        self::assertNotContains($today->format('Y-m-d'), $dates);
        self::assertNotContains($today->modify('+1 day')->format('Y-m-d'), $dates);

        if ($dayOfMonth > 1) {
            self::assertContains($today->modify('-1 day')->format('Y-m-d'), $dates);
        } else {
            self::assertSame([], $dates);
        }
    }

    public function testFiltersByCompanyId(): void
    {
        $month = new \DateTimeImmutable('2026-04-01');

        $query = $this->createMock(ActiveOzonConnectionsQuery::class);
        $query->expects(self::once())->method('execute')->with(self::COMPANY_ID)->willReturn([
            ['id' => self::CONNECTION_ID, 'company_id' => self::COMPANY_ID, 'finance_lock_before' => null],
        ]);

        $planner = new OzonMonthRawRefreshPlanner($query);
        $plan = $planner->plan((int) $month->format('Y'), (int) $month->format('m'), self::COMPANY_ID);

        self::assertNotEmpty($plan);
        self::assertSame(self::COMPANY_ID, $plan[0]->companyId);
    }

    public function testMarksFinanceLockedDatesAsSkipped(): void
    {
        $month = new \DateTimeImmutable('2026-04-01');

        $query = $this->createMock(ActiveOzonConnectionsQuery::class);
        $query->method('execute')->willReturn([
            ['id' => self::CONNECTION_ID, 'company_id' => self::COMPANY_ID, 'finance_lock_before' => '2026-04-10'],
        ]);

        $planner = new OzonMonthRawRefreshPlanner($query);
        $plan = $planner->plan(2026, 4, null, $month, $month->modify('+14 day'));

        $byDate = [];
        foreach ($plan as $item) {
            $byDate[$item->date] = $item;
        }

        self::assertSame('skipped', $byDate['2026-04-01']->status);
        self::assertSame('finance_locked', $byDate['2026-04-01']->skippedReason);
        self::assertSame('skipped', $byDate['2026-04-10']->status);
        self::assertSame('planned', $byDate['2026-04-11']->status);
    }

    public function testReturnsEmptyPlanWhenNoActiveConnections(): void
    {
        $query = $this->createMock(ActiveOzonConnectionsQuery::class);
        $query->method('execute')->willReturn([]);

        $planner = new OzonMonthRawRefreshPlanner($query);
        $plan = $planner->plan(2026, 4);

        self::assertSame([], $plan);
    }

    public function testIsReadOnlyAndUsesOnlyConnectionsQuery(): void
    {
        $query = $this->createMock(ActiveOzonConnectionsQuery::class);
        $query->expects(self::once())->method('execute')->willReturn([
            ['id' => self::CONNECTION_ID, 'company_id' => self::COMPANY_ID, 'finance_lock_before' => null],
        ]);

        $planner = new OzonMonthRawRefreshPlanner($query);
        $plan = $planner->plan(2026, 4, null, new \DateTimeImmutable('2026-04-10'), new \DateTimeImmutable('2026-04-12'));

        self::assertCount(3, $plan);
    }
}
