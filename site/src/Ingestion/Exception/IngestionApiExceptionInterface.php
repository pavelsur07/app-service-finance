<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

interface IngestionApiExceptionInterface
{
    public function errorCode(): string;

    public function publicMessage(): string;
}
