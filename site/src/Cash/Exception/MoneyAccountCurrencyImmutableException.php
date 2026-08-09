<?php

declare(strict_types=1);

namespace App\Cash\Exception;

final class MoneyAccountCurrencyImmutableException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Валюту существующего счёта нельзя изменить.');
    }
}
