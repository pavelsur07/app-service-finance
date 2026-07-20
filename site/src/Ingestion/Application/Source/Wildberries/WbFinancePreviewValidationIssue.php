<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

final readonly class WbFinancePreviewValidationIssue
{
    public function __construct(
        public string $rowKey,
        public string $operationGroupId,
        public string $field,
        public string $reason,
    ) {
    }
}
