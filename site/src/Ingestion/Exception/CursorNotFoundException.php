<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class CursorNotFoundException extends \DomainException
{
    public function getErrorCode(): string
    {
        return 'sync_cursor_not_found';
    }
}
