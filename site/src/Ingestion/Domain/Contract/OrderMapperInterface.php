<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Contract;

use App\Ingestion\Application\DTO\MappedOrderBatch;
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
     * Возвращает и разобранные заказы, и отброшенные строки: молчаливый
     * пропуск невозможно заметить, а курсор после нормализации уже уехал.
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public function map(IngestRawRecord $rawRecord, iterable $rows): MappedOrderBatch;
}
