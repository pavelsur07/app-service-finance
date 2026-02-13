<?php

namespace App\Analytics\Api\Response;

final readonly class InflowWidgetResponse
{
    /**
     * @param list<array{date:string,value:float}> $series
     * @param array<string, mixed>                 $drilldown
     */
    public function __construct(
        private float $sum,
        private float $deltaAbs,
        private float $deltaPct,
        private float $avgDaily,
        private array $series,
        private array $drilldown = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sum' => $this->sum,
            'delta_abs' => $this->deltaAbs,
            'delta_pct' => $this->deltaPct,
            'avg_daily' => $this->avgDaily,
            'series' => $this->series,
            'drilldown' => $this->drilldown,
        ];
    }
}

