<?php

declare(strict_types=1);

namespace App\Cash\Exception;

final class CashTransactionNotFoundException extends \RuntimeException implements CashApiExceptionInterface, \App\Shared\Domain\Exception\UserFacingException
{
    public function __construct()
    {
        parent::__construct('Транзакция не найдена.');
    }

    public function httpStatus(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'cash_transaction_not_found';
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }
}
