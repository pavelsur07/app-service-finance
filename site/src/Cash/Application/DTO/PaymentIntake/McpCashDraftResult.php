<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO\PaymentIntake;

final readonly class McpCashDraftResult
{
    public function __construct(public string $draftId, public string $status)
    {
    }
}
