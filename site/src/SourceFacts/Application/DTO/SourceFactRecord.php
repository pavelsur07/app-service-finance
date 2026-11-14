<?php

declare(strict_types=1);

namespace App\SourceFacts\Application\DTO;

/**
 * Действующая запись источника как есть: разбирает её переводчик канала.
 */
final readonly class SourceFactRecord
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $sourceKey,
        public array $payload,
    ) {
    }
}
