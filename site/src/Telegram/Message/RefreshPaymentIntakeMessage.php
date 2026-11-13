<?php

declare(strict_types=1);

namespace App\Telegram\Message;

final readonly class RefreshPaymentIntakeMessage
{
    public function __construct(public string $companyId, public string $sessionId, public int $attempt = 0, public string $generation = '', public int $sequence = 0)
    {
    }
}
