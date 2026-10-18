<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Итог применения одной версии справочника Ozon `accrual/types`.
 *
 * `stale` — версия старше уже применённой: из неё взяты только неизвестные
 * типы, названия и пропажи не тронуты. `skippedRows` — строки без `id` или
 * `name`; версия, где валидных строк нет совсем, не меняет ничего (`empty`).
 */
final readonly class OzonAccrualTypesSyncResult
{
    /**
     * @param list<int> $createdTypeIds
     * @param list<int> $updatedTypeIds
     * @param list<int> $removedTypeIds
     */
    public function __construct(
        public string $rawRecordId,
        public int $rows = 0,
        public int $unchanged = 0,
        public int $skippedRows = 0,
        public array $createdTypeIds = [],
        public array $updatedTypeIds = [],
        public array $removedTypeIds = [],
        public bool $stale = false,
        public bool $empty = false,
        public bool $dryRun = false,
    ) {
    }

    public function hasChanges(): bool
    {
        return [] !== $this->createdTypeIds || [] !== $this->updatedTypeIds || [] !== $this->removedTypeIds;
    }
}
