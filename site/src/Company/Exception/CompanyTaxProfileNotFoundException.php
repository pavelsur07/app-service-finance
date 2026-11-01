<?php

declare(strict_types=1);

namespace App\Company\Exception;

final class CompanyTaxProfileNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Период налогового режима не найден.');
    }
}
