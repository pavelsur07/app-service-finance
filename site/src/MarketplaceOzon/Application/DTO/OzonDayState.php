<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Application\DTO;

/** Переведённый день кабинета: ревизия фактов, актуальность правил и суммы `total = canon + quarantine`. */
final readonly class OzonDayState
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
