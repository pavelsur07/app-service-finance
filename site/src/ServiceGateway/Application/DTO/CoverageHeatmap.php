<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

/**
 * Тепловая карта покрытия месяца для страницы админки.
 */
final readonly class CoverageHeatmap
{
    /**
     * @param list<CoverageHeatmapDay> $days
     * @param list<CoverageHeatmapRow> $rows
     */
    public function __construct(
        public string $month,
        public array $days,
        public array $rows,
        public ?\DateTimeImmutable $refreshedAt,
        public bool $stale,
        public ?int $cabinetsTotal,
        public ?int $coveragePercent,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->refreshedAt;
    }
}
