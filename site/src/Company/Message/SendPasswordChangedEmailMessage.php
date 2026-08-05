<?php

declare(strict_types=1);

namespace App\Company\Message;

final readonly class SendPasswordChangedEmailMessage
{
    public function __construct(
        public string $userId,
        public \DateTimeImmutable $changedAt,
    ) {
    }
}
