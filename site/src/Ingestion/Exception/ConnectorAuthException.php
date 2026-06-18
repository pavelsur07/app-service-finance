<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class ConnectorAuthException extends \RuntimeException
{
    public function getErrorCode(): string
    {
        return 'connector_auth_failed';
    }
}
