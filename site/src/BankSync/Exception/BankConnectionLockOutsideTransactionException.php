<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/**
 * Транзакционная блокировка подключения взята вне транзакции — ошибка вызывающего кода:
 * PostgreSQL снял бы её сразу после запроса.
 */
final class BankConnectionLockOutsideTransactionException extends \LogicException
{
    public function __construct()
    {
        parent::__construct('Bank connection token lock requires an open transaction.');
    }
}
