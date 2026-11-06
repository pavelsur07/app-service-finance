<?php

declare(strict_types=1);

namespace App\Finance\Domain\Event;

final readonly class FinanceSettlementSourceChanging
{
    public function __construct(public string $companyId, public string $sourceId, public bool $materialChange)
    {
    }
}
