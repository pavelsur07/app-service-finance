<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/**
 * Денежный счёт нельзя привязать к счёту банка: не найден в компании, неактивен или в
 * другой валюте (`reason`: `not_found`, `currency`).
 */
final class MoneyAccountNotLinkableException extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const CURRENCY = 'currency';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(sprintf('Money account cannot be linked to the bank account: %s.', $reason));
    }
}
