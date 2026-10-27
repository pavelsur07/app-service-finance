<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

final class BankAccountNotFoundException extends \RuntimeException
{
    public function __construct(string $bankAccountId)
    {
        parent::__construct(sprintf('Bank account %s not found in company.', $bankAccountId));
    }
}
