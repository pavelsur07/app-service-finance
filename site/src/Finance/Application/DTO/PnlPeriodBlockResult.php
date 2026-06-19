<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

final readonly class PnlPeriodBlockResult
{
    private function __construct(public string $reason)
    {
    }

    public static function blocked(string $reason): self
    {
        return new self($reason);
    }
}
