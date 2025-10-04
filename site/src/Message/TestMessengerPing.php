<?php

declare(strict_types=1);

namespace App\Message;

final readonly class TestMessengerPing
{
    public function __construct(
        public string             $id,          // hex/uuid
        public string             $companyId,   // можно прокинуть из контекста компании
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {}
}
