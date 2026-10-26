<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/**
 * Для кода банка нет адаптера: подключение создано для банка, который приложение не
 * поддерживает, или адаптер не зарегистрирован.
 */
final class UnknownBankCodeException extends \RuntimeException
{
    public function __construct(string $bankCode)
    {
        parent::__construct(sprintf('No bank provider registered for "%s".', $bankCode));
    }
}
