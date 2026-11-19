<?php

declare(strict_types=1);

namespace App\Company\Application\ProjectDirection;

use App\Company\Repository\ProjectDirectionRepository;

/**
 * @phpstan-import-type ProjectRow from ProjectDirectionRepository
 *
 * @phpstan-type CopyDecision array{mode: string, code: string}
 * @phpstan-type CopyPreviewRow array{id: string, name: string, sourceCode: ?string, code: ?string, indent: string, action: string, detail: string, conflict: ?string, decision: CopyDecision}
 */
final readonly class ProjectDirectionCopyPreview
{
    /**
     * @param list<CopyPreviewRow> $rows
     * @param array<string, ProjectRow> $creations
     * @param array<string, string> $mappings
     * @param list<string> $errors
     */
    public function __construct(
        public ?string $sourceRevision,
        public ?string $targetRevision,
        public array $rows = [],
        public array $creations = [],
        public array $mappings = [],
        public array $errors = [],
    ) {
    }
}
