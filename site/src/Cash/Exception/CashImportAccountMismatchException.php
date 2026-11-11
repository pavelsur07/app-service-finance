<?php

declare(strict_types=1);

namespace App\Cash\Exception;

final class CashImportAccountMismatchException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Счёт импорта не принадлежит компании.');
    }
}
