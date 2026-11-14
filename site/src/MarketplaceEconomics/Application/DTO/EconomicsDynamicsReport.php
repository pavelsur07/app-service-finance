<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\DTO;

use App\MarketplaceEconomics\Domain\SkuMetrics;

final readonly class EconomicsDynamicsReport
{
    public const STEPS = ['day', 'week', 'month'];

    /**
     * @param list<array{from: \DateTimeImmutable, to: \DateTimeImmutable, metrics: SkuMetrics}> $steps все шаги периода по порядку, без данных — нули; края обрезаны периодом
     * @param list<string> $connections кабинеты с витриной
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public string $step,
        public array $steps,
        /** Σ шагов: без фильтра — кабинет (с нераспределённым), с фильтром — выбранные SKU. */
        public SkuMetrics $total,
        public array $connections,
    ) {
    }

    public function hasData(): bool
    {
        return [] !== $this->connections;
    }
}
