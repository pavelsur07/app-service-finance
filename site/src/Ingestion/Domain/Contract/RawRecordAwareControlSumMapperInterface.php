<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Contract;

use App\Ingestion\Application\DTO\MappedControlSum;
use App\Ingestion\Entity\IngestRawRecord;

interface RawRecordAwareControlSumMapperInterface
{
    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedControlSum>
     */
    public function controlSumForRawRecord(IngestRawRecord $rawRecord, iterable $rows): array;
}
