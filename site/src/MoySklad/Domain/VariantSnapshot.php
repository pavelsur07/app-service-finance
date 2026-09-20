<?php

declare(strict_types=1);

namespace App\MoySklad\Domain;

final readonly class VariantSnapshot
{
    /** @param list<array{id: string, name: string, value: string}> $characteristics */
    public function __construct(
        public string $externalId,
        public string $productExternalId,
        public string $name,
        public string $externalCode,
        public ?string $code,
        public ?string $article,
        public array $characteristics,
        public bool $archived,
        public \DateTimeImmutable $sourceUpdatedAt,
    ) {
    }
}
