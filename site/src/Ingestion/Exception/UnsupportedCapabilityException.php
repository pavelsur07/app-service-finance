<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class UnsupportedCapabilityException extends \DomainException
{
    public function getErrorCode(): string
    {
        return 'connector_capability_unsupported';
    }
}
