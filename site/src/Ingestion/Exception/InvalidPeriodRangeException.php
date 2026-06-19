<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class InvalidPeriodRangeException extends \InvalidArgumentException implements IngestionApiExceptionInterface
{
    public function __construct()
    {
        parent::__construct('Некорректный диапазон периода');
    }

    public function errorCode(): string
    {
        return 'invalid_period_range';
    }

    public function publicMessage(): string
    {
        return 'Некорректный диапазон периода';
    }
}
