<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Contract;

use App\Ingestion\Application\DTO\MappedControlSum;
use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;

interface SourceMapperInterface
{
    public function source(): IngestSource;

    /**
     * @return list<string>
     */
    public function resourceTypes(): array;

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedTransaction>
     */
    public function map(IngestRawRecord $rawRecord, iterable $rows): array;

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedControlSum>
     */
    public function controlSum(iterable $rows): array;
}
