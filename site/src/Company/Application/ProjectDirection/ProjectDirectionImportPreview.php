<?php

declare(strict_types=1);

namespace App\Company\Application\ProjectDirection;

use App\Company\Repository\ProjectDirectionRepository;

/** @phpstan-import-type ProjectRow from ProjectDirectionRepository */
final readonly class ProjectDirectionImportPreview
{
    /**
     * @param list<string>              $errors
     * @param array<string, ProjectRow> $changes
     */
    public function __construct(
        public ?string $revision,
        public int $created = 0,
        public int $updated = 0,
        public int $unchanged = 0,
        public array $errors = [],
        public array $changes = [],
    ) {
    }
}
