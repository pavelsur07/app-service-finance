<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

use App\Shared\Domain\ValueObject\Money;

final readonly class SettlementReportTotals
{
    public function __construct(public SettlementBalances $opening, public SettlementBalances $increases, public SettlementBalances $decreases, public SettlementBalances $closing, public Money $overdue, public Money $withoutDueDate)
    {
    }
}
