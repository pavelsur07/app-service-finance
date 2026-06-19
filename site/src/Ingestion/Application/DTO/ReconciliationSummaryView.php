<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

final readonly class ReconciliationSummaryView
{
    public function __construct(
        public string $period,
        public int $canonTotalMinor,
        public ?int $ozonControlTotalMinor,
        public string $currency,
        public ?int $canonVsOzonDeltaMinor,
        public int $thresholdMinor,
        public string $recomputedAt,
    ) {
    }

    /**
     * @return array{period: string, canon_total_minor: int, ozon_control_total_minor: int|null, currency: string, canon_vs_ozon_delta_minor: int|null, threshold_minor: int, recomputed_at: string}
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'canon_total_minor' => $this->canonTotalMinor,
            'ozon_control_total_minor' => $this->ozonControlTotalMinor,
            'currency' => $this->currency,
            'canon_vs_ozon_delta_minor' => $this->canonVsOzonDeltaMinor,
            'threshold_minor' => $this->thresholdMinor,
            'recomputed_at' => $this->recomputedAt,
        ];
    }
}
