<?php

declare(strict_types=1);

namespace App\Company\Application\ProjectDirection;

/** @phpstan-import-type CopyDecision from ProjectDirectionCopyPreview */
final readonly class ProjectDirectionCopyDraft
{
    /** @param array<string, CopyDecision> $decisions */
    public function __construct(
        public ?string $sourceRevision,
        public ?string $targetRevision,
        public array $decisions,
    ) {
    }

    public static function scope(string $sourceId, string $targetId): string
    {
        return 'copy:'.$sourceId.':'.$targetId;
    }
}
