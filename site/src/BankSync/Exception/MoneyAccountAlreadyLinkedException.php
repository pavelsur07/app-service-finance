<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/**
 * Денежный счёт уже получает выписку из другого счёта банка.
 */
final class MoneyAccountAlreadyLinkedException extends \RuntimeException
{
    public function __construct(string $moneyAccountId)
    {
        parent::__construct(sprintf('Money account %s is already linked to another bank account.', $moneyAccountId));
    }
}
