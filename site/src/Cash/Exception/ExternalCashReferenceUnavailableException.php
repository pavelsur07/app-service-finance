<?php

declare(strict_types=1);

namespace App\Cash\Exception;

final class ExternalCashReferenceUnavailableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Счёт или статья ДДС недоступны.');
    }
}
