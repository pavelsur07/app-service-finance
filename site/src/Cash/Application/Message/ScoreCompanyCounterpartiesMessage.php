<?php

declare(strict_types=1);

namespace App\Cash\Application\Message;

final readonly class ScoreCompanyCounterpartiesMessage
{
    public function __construct(
        public string $companyId,
    ) {}
}
