<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class ConnectorTransientException extends \RuntimeException
{
    public function getErrorCode(): string
    {
        return 'connector_transient_error';
    }
}
