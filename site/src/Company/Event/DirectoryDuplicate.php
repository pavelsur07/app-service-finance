<?php

declare(strict_types=1);

namespace App\Company\Event;

/**
 * Соседние статьи справочника (один родитель) с одинаковым названием — находка ночной проверки.
 */
final readonly class DirectoryDuplicate
{
    public function __construct(
        public string $directory,
        public string $companyId,
        public string $name,
        public int $count,
        public ?string $parentName,
    ) {
    }
}
