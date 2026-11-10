<?php

declare(strict_types=1);

namespace App\Cash\Http;

use App\Cash\Entity\Accounts\MoneyAccount;

final readonly class CashImportSessionState
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload, public MoneyAccount $account)
    {
    }
}
