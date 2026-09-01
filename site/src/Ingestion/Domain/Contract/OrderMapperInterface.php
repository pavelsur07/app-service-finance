<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Contract;

use App\Ingestion\Application\DTO\MappedOrder;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;

/**
 * Контракт маппера заказов.
 *
 * Отдельный от {@see SourceMapperInterface}: тот возвращает list<MappedTransaction>
 * и обслуживает финансовый путь, который эта задача не трогает.
 */
interface OrderMapperInterface
{
    public function source(): IngestSource;

    /**
     * @return list<string>
     */
    public function resourceTypes(): array;

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<MappedOrder>
     */
    public function map(IngestRawRecord $rawRecord, iterable $rows): array;
}
