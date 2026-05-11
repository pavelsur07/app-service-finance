<?php

declare(strict_types=1);

namespace App\Telegram\Application\DTO;

final readonly class CreateTelegramCashTransactionActionResult
{
    private function __construct(
        public bool $duplicate,
        public string $amount,
        public string $directionLabel,
        public bool $skippedMissingMessageIdentity,
    ) {}

    public static function created(bool $duplicate, string $amount, string $directionLabel): self
    {
        return new self($duplicate, $amount, $directionLabel, false);
    }

    public static function skippedDueToMissingMessageIdentity(): self
    {
        return new self(false, '0.00', '', true);
    }
}
