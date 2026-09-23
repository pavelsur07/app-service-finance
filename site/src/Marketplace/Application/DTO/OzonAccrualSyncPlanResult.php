<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Итог постановки задач by-day: сколько отправлено и за какие дни.
 *
 * `firstDay`/`lastDay` — фактическое окно после обрезки (Y-m-d); `null`, если
 * после обрезки не осталось ни одного дня. `clampedToSafeDay` — начало окна
 * поднято до `OzonAccrualSyncPlanner::EARLIEST_SAFE_DAY`.
 */
final readonly class OzonAccrualSyncPlanResult
{
    public function __construct(
        public int $dispatchedCount,
        public ?string $firstDay,
        public ?string $lastDay,
        public bool $clampedToSafeDay,
    ) {
    }
}
