<?php

declare(strict_types=1);

namespace App\Billing\Exception;

final class PerpetualTariffChangeNotConfirmedException extends \RuntimeException
{
    public function __construct(string $tariffName)
    {
        parent::__construct(sprintf('Кабинет на бессрочном тарифе «%s»: подтвердите перевод на платный.', $tariffName));
    }
}
