<?php

declare(strict_types=1);

namespace App\MoySklad\Domain;

/** Предложение назначения склада: код счёта типового справочника Баланса или «не учитывать». */
final readonly class StoreAccountSuggestion
{
    public function __construct(
        public ?string $accountCode,
        public bool $excluded,
        public string $reason,
        public ?string $warning = null,
    ) {
    }
}
