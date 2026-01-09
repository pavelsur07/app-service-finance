<?php

namespace App\Message;

final readonly class SendRegistrationEmailMessage
{
    public function __construct(
        public string $userId,
        public string $companyId,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
