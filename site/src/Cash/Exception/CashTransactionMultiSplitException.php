<?php

declare(strict_types=1);

namespace App\Cash\Exception;

use App\Shared\Domain\Exception\UserFacingException;

final class CashTransactionMultiSplitException extends \RuntimeException implements UserFacingException, CashApiExceptionInterface
{
    public function __construct()
    {
        parent::__construct('У операции несколько строк разбивки. Сумму, валюту и статьи меняйте через разбивку; создание документа ОПиУ недоступно.');
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'cash_transaction_multi_split';
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }
}
