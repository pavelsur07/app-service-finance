<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class AiCheck
{
    public function __construct(
        public string $name,
        public bool $passed,
        /** block | review | info */
        public string $severity,
        public string $details,
    ) {
    }
}
