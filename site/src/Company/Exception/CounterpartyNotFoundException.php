<?php

declare(strict_types=1);

namespace App\Company\Exception;

final class CounterpartyNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Контрагент не найден.');
    }
}
