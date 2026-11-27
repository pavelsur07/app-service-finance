<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * План разметки тегов из файла (предпросмотр) или итог его применения.
 * `fingerprint` — отпечаток плана: применяется только план, совпавший с показанным.
 */
final readonly class ListingTagsImportResult
{
    /**
     * @param list<array{row: int, listingId: string, name: string, add: list<string>, remove: list<string>}> $changes
     * @param list<string> $newTags
     * @param list<array{row: int, message: string}> $errors
     */
    public function __construct(
        public array $changes,
        public array $newTags,
        public array $errors,
        public string $fingerprint,
        public bool $applied,
    ) {
    }

    public function added(): int
    {
        return array_sum(array_map(static fn (array $change): int => \count($change['add']), $this->changes));
    }

    public function removed(): int
    {
        return array_sum(array_map(static fn (array $change): int => \count($change['remove']), $this->changes));
    }

    public function isEmpty(): bool
    {
        return [] === $this->changes;
    }
}
