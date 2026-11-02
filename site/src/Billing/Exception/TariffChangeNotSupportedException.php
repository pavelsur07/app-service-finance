<?php

declare(strict_types=1);

namespace App\Billing\Exception;

/**
 * Эту смену тарифа при действующем оплаченном периоде оплатой не сделать: скрытый тариф, равная
 * цена или уже запланирован переход на другой тариф.
 */
final class TariffChangeNotSupportedException extends \RuntimeException
{
    public function __construct(string $message = 'Эту смену тарифа при действующем оплаченном периоде можно сделать только через администратора.')
    {
        parent::__construct($message);
    }
}
