<?php

declare(strict_types=1);

namespace App\Cash\Exception;

final class BalanceNotAvailableBeforeOpeningDateException extends \RuntimeException implements CashApiExceptionInterface
{
    public function __construct()
    {
        parent::__construct('Остаток недоступен до даты ввода начального сальдо.');
    }

    public function errorCode(): string
    {
        return 'balance_not_available_before_opening_date';
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }
}
