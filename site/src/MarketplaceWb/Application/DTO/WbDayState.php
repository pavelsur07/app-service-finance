<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Application\DTO;

/** Переведённый день кабинета WB: ревизия отчёта о продажах, актуальность правил и суммы `total = canon + quarantine`. */
final readonly class WbDayState
{
    public function __construct(
        public string $connectionRef,
        public string $date,
        public int $factsRevision,
        public bool $currentRules,
        public int $totalMinor,
        public int $canonMinor,
        public int $quarantineMinor,
    ) {
    }
}
