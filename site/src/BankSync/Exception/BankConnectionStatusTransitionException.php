<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

use App\BankSync\Enum\BankConnectionStatus;

/**
 * Переход статуса подключения, которого нет в матрице: например, включить флажком
 * подключение с отозванным доступом.
 */
final class BankConnectionStatusTransitionException extends \DomainException
{
    public function __construct(BankConnectionStatus $from, BankConnectionStatus $to)
    {
        parent::__construct(sprintf('Bank connection status cannot change from "%s" to "%s".', $from->value, $to->value));
    }
}
