<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

use App\Shared\Domain\ValueObject\Money;

final readonly class SettlementBalances
{
    public function __construct(public Money $receivable, public Money $payable, public Money $advanceGiven, public Money $advanceReceived, public Money $unallocatedOut, public Money $unallocatedIn)
    {
    }
}
