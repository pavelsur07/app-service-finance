<?php

declare(strict_types=1);

namespace App\Cash\Exception;

final class CashInvalidCsrfException extends \RuntimeException implements CashApiExceptionInterface, \App\Shared\Domain\Exception\UserFacingException
{
    public function __construct()
    {
        parent::__construct('Неверный CSRF-токен.');
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'cash_invalid_csrf';
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }
}
