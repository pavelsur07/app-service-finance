<?php

declare(strict_types=1);

namespace App\Cash\Exception;

interface CashApiExceptionInterface
{
    public function errorCode(): string;

    public function publicMessage(): string;
}
