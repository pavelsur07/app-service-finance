<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class MapperNotFoundException extends \RuntimeException
{
    public function getErrorCode(): string
    {
        return 'mapper_not_found';
    }
}
