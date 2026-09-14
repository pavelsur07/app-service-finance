<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

use App\MoySklad\Enum\ConnectionCheckStatus;

final readonly class ConnectionCheckResult
{
    public function __construct(
        public ConnectionCheckStatus $status,
        public ?string $accountId = null,
    ) {
    }
}
