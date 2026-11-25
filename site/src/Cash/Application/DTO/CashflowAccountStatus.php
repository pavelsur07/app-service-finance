<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CashflowAccountStatus
{
    public function __construct(
        public string $id,
        public string $companyId,
        public string $label,
        public ?string $lastDate,
        public bool $lagging,
    ) {
    }
}
