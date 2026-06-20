<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

final readonly class OzonAccrualByDayRawAggregate
{
    /**
     * @param list<array{date: string, category: string, count: int, totalMinor: int}> $dateCategoryRows
     * @param list<array{date: string, typeId: string, count: int, totalMinor: int}> $deliveryServiceRows
     * @param list<array{date: string, field: string, count: int, totalMinor: int}> $commissionRows
     * @param list<array{date: string, typeId: string, count: int, totalMinor: int}> $itemFeeRows
     * @param list<array{date: string, typeId: string, count: int, totalMinor: int}> $nonItemFeeRows
     * @param list<array{date: string, typeId: string, count: int, totalMinor: int}> $containerFeeRows
     */
    public function __construct(
        public int $scannedRows,
        public array $dateCategoryRows,
        public array $deliveryServiceRows,
        public array $commissionRows,
        public array $itemFeeRows,
        public array $nonItemFeeRows,
        public array $containerFeeRows,
    ) {
    }
}
