<?php

declare(strict_types=1);

namespace App\BankSync\Enum;

/**
 * Направление операции с точки зрения счёта клиента: списание или поступление.
 */
enum BankDirection: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';
}
