<?php

declare(strict_types=1);

namespace App\Cash\Exception;

final class OpeningBalanceDateInFutureException extends \RuntimeException implements CashApiExceptionInterface
{
    public function __construct()
    {
        parent::__construct('Дата ввода начального сальдо не может быть позже сегодняшнего дня.');
    }

    public function errorCode(): string
    {
        return 'opening_balance_date_in_future';
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }
}
