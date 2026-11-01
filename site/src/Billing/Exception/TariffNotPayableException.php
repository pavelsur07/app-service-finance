<?php

declare(strict_types=1);

namespace App\Billing\Exception;

use App\Billing\Enum\TariffCode;

final class TariffNotPayableException extends \RuntimeException
{
    public function __construct(TariffCode $code)
    {
        parent::__construct(sprintf('Тариф «%s» не найден, отключён или бессрочный — оплатить его за период нельзя.', $code->value));
    }
}
