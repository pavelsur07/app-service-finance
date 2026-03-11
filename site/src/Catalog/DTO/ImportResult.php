<?php

declare(strict_types=1);

namespace App\Catalog\DTO;

final class ImportResult
{
    /**
     * @param ImportRowError[] $errors
     */
    public function __construct(
        public readonly int   $created,
        public readonly int   $skipped,
        public readonly array $errors,
    ) {
    }

    public function total(): int
    {
        return $this->created + $this->skipped;
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    /** @return array<int, array{row: int, reason: string, message: string}> */
    public function errorsToArray(): array
    {
        return array_map(static fn(ImportRowError $e) => $e->toArray(), $this->errors);
    }
}
