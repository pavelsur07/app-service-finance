<?php

declare(strict_types=1);

namespace App\MoySklad\Domain;

final readonly class ProductSnapshot
{
    public function __construct(
        public string $externalId,
        public string $name,
        public string $externalCode,
        public ?string $code,
        public ?string $article,
        public int $variantsCount,
        public bool $archived,
        public \DateTimeImmutable $sourceUpdatedAt,
    ) {
    }
}
