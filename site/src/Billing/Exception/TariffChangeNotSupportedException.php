<?php

declare(strict_types=1);

namespace App\Billing\Exception;

final class TariffChangeNotSupportedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Оплата другого тарифа при действующем оплаченном периоде пока не поддерживается.');
    }
}
