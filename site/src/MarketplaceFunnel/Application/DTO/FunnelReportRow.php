<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Application\DTO;

/**
 * Строка экрана «Воронка»: товар маркетплейса за период. Ступень без данных — `null`.
 */
final readonly class FunnelReportRow
{
    /**
     * @param array<string, int|null> $values колонка `FunnelRow::COLUMNS` → сумма за период
     */
    public function __construct(
        public string $channel,
        public string $sku,
        public ?string $title,
        public ?string $supplierSku,
        public array $values,
    ) {
    }

    /** Конверсия между ступенями, %; `null` — одной из ступеней нет либо база нулевая. */
    public function conversion(string $from, string $to): ?float
    {
        $base = $this->values[$from] ?? null;
        $value = $this->values[$to] ?? null;

        return null === $base || null === $value || 0 === $base ? null : $value * 100 / $base;
    }
}
