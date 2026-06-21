<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\MappedControlSum;
use App\Ingestion\Application\DTO\MappedTransaction;
use App\Ingestion\Domain\Contract\SourceMapperInterface;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;

final readonly class OzonAccrualShadowMapper implements SourceMapperInterface
{
    public function source(): IngestSource
    {
        return IngestSource::OZON;
    }

    /**
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        return [
            OzonResourceType::ACCRUAL_POSTINGS,
            OzonResourceType::ACCRUAL_TYPES,
        ];
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedTransaction>
     */
    public function map(IngestRawRecord $rawRecord, iterable $rows): array
    {
        return [];
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
}
