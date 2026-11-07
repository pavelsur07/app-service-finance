<?php

declare(strict_types=1);

namespace App\Cash\Domain\Event;

final readonly class CashSettlementSourceChanging
{
    public function __construct(public string $companyId, public string $sourceId, public bool $materialChange)
    {
    }
}
