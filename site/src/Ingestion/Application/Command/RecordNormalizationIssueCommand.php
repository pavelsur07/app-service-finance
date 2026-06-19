<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Command;

use App\Ingestion\Enum\NormalizationIssueKind;
use Webmozart\Assert\Assert;

final readonly class RecordNormalizationIssueCommand
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $companyId,
        public string $rawRecordId,
        public ?string $operationGroupId,
        public NormalizationIssueKind $kind,
        public array $details,
    ) {
        Assert::uuid($this->companyId);
        Assert::uuid($this->rawRecordId);

        if (null !== $this->operationGroupId) {
            Assert::uuid($this->operationGroupId);
        }
    }
}
