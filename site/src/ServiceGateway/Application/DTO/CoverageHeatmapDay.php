<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class CoverageHeatmapDay
{
    public function __construct(
        public int $day,
        public bool $weekend,
        public bool $today,
    ) {
    }
}
