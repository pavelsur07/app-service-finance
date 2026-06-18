<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class ConnectorNotFoundException extends \RuntimeException
{
    public function getErrorCode(): string
    {
        return 'connector_not_found';
    }
}
