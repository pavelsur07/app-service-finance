<?php

declare(strict_types=1);

namespace App\BankSync\Application\DTO;

final readonly class BankAccountDTO
{
    public function __construct(public string $accountNumber)
    {
    }
}
