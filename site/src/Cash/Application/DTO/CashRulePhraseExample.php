<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CashRulePhraseExample
{
    public function __construct(
        public string $id,
        public string $occurredAt,
        public string $description,
        public string $categoryId,
        public string $categoryName,
    ) {
    }
}
