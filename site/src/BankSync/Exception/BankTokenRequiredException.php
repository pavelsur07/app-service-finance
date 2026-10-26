<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/**
 * Новое подключение без токена: загружать выписку нечем.
 */
final class BankTokenRequiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Bank token is required for a new connection.');
    }
}
