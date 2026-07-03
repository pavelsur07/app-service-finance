<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Contract;

use App\Ingestion\Application\DTO\MappedPreviewIssue;
use App\Ingestion\Entity\IngestRawRecord;

interface PreviewIssueAwareMapperInterface
{
    /**
     * Non-blocking diagnostic issues detected during mapping preview.
     * Recording them must not prevent the mapped transactions from being written.
     *
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedPreviewIssue>
     */
    public function previewIssues(IngestRawRecord $rawRecord, iterable $rows): array;
}
