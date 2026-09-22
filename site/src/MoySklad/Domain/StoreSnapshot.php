<?php

declare(strict_types=1);

namespace App\MoySklad\Domain;

final readonly class StoreSnapshot
{
    public function __construct(
        public string $externalId,
        public string $name,
        public string $externalCode,
        public ?string $code,
        public string $pathName,
        public bool $archived,
        public \DateTimeImmutable $sourceUpdatedAt,
    ) {
    }
}
