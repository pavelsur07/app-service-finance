<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

final class BankConnectionNotFoundException extends \RuntimeException
{
    public function __construct(string $connectionId)
    {
        parent::__construct(sprintf('Bank connection %s not found in company.', $connectionId));
    }
}
