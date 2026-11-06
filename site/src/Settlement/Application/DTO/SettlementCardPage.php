<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

final readonly class SettlementCardPage
{
    /** @param list<SettlementMovementRow> $rows */
    public function __construct(public array $rows, public int $total, public SettlementCounterpartyRow $summary)
    {
    }
}
