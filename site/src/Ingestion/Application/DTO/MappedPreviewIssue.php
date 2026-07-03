<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use App\Ingestion\Enum\NormalizationIssueKind;

final readonly class MappedPreviewIssue
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public ?string $operationGroupId,
        public NormalizationIssueKind $kind,
        public array $details,
    ) {
    }
}
