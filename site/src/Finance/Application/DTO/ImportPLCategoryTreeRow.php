<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

final readonly class ImportPLCategoryTreeRow
{
    public function __construct(
        public string $name,
        public ?string $code,
        public string $path,
    ) {
    }
}
