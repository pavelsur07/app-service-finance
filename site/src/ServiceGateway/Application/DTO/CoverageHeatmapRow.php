<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class CoverageHeatmapRow
{
    /**
     * @param list<CoverageHeatmapCell> $cells одна на день месяца, либо одна на весь месяц ($perDay = false)
     */
    public function __construct(
        public string $title,
        public string $resourceType,
        public bool $perDay,
        public array $cells,
        public int $errCount,
        public int $partialCount,
    ) {
    }
}
