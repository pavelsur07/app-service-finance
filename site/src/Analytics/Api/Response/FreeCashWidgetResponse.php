<?php

namespace App\Analytics\Api\Response;

use DateTimeImmutable;

final readonly class FreeCashWidgetResponse
{
    /**
     * @param array<string, mixed> $drilldown
     */
    public function __construct(
        private float $value,
        private float $deltaAbs,
        private float $deltaPct,
        private float $cashAtEnd,
        private float $reservedAtEnd,
        private ?DateTimeImmutable $lastUpdatedAt,
        private array $drilldown = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'delta_abs' => $this->deltaAbs,
            'delta_pct' => $this->deltaPct,
            'cash_at_end' => $this->cashAtEnd,
            'reserved_at_end' => $this->reservedAtEnd,
            'last_updated_at' => $this->lastUpdatedAt?->format(DATE_ATOM),
            'drilldown' => $this->drilldown,
        ];
    }
}
