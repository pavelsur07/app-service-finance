<?php

declare(strict_types=1);

namespace App\MarketplaceOperations\Application\DTO;

/** Σ канона кабинета за день без справочных видов (СПП, соинвестирование) — для сверок. */
final readonly class CanonDaySum
{
    public function __construct(
        public string $companyId,
        public string $connectionRef,
        public string $date,
        public int $amountMinor,
    ) {
    }
}
