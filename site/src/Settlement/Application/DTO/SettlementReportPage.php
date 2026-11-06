<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

final readonly class SettlementReportPage
{
    /** @param list<SettlementCounterpartyRow> $rows */
    public function __construct(public array $rows, public int $total, public SettlementReportTotals $totals)
    {
    }
}
