<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class InvalidPeriodException extends \InvalidArgumentException implements IngestionApiExceptionInterface
{
    public function __construct()
    {
        parent::__construct('Некорректный период');
    }

    public function errorCode(): string
    {
        return 'invalid_period';
    }

    public function publicMessage(): string
    {
        return 'Некорректный период';
    }
}
