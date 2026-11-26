<?php

declare(strict_types=1);

namespace App\OneC\Application\DTO;

final readonly class PackagePart
{
    public function __construct(
        public string $id,
        public int $part,
        public int $documentCount,
        public int $byteSize,
        public \DateTimeImmutable $receivedAt,
    ) {
    }
}
