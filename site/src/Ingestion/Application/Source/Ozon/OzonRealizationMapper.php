<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\MappedControlSum;
use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Domain\Contract\RawRecordAwareControlSumMapperInterface;
use App\Ingestion\Domain\Contract\SourceMapperInterface;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;

final readonly class OzonRealizationMapper implements SourceMapperInterface, RawRecordAwareControlSumMapperInterface
{
    public function __construct(private OzonTransactionComponentMapper $mapper)
    {
    }

    public function source(): IngestSource
    {
        return IngestSource::OZON;
    }

    /**
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        return [OzonResourceType::REALIZATION];
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedTransaction>
     */
    public function map(IngestRawRecord $rawRecord, iterable $rows): array
    {
        return $this->mapper->map($rawRecord, $rows, realization: true);
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedControlSum>
     */
    public function controlSum(iterable $rows): array
    {
        return [];
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedControlSum>
     */
    public function controlSumForRawRecord(IngestRawRecord $rawRecord, iterable $rows): array
    {
        return $this->mapper->controlSumForRawRecord($rawRecord, $rows);
    }
}
