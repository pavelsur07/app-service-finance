<?php

declare(strict_types=1);

namespace App\Ingestion\Facade;

use App\Ingestion\Application\ReadRawRecordAction;
use App\Ingestion\Application\StoreRawBatchAction;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestRawRecord;

final readonly class RawStorageFacade
{
    public function __construct(
        private StoreRawBatchAction $storeRawBatchAction,
        private ReadRawRecordAction $readRawRecordAction,
    ) {
    }

    /**
     * @return list<IngestRawRecord>
     */
    public function store(RawBatch $batch): array
    {
        return ($this->storeRawBatchAction)($batch);
    }

    /**
     * Скалярные идентификаторы вместо сущностей — для вызывающих из других
     * модулей: App\Ingestion\Entity\* не должны пересекать границу модуля,
     * это проверяет tests/Unit/Ingestion/Architecture/EntityBoundaryTest.
     *
     * @return list<string>
     */
    public function storeAndGetIds(RawBatch $batch): array
    {
        return array_map(
            static fn (IngestRawRecord $record): string => $record->getId(),
            $this->store($batch),
        );
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    public function read(string $rawRecordId, string $companyId): iterable
    {
        return ($this->readRawRecordAction)($rawRecordId, $companyId);
    }
}
