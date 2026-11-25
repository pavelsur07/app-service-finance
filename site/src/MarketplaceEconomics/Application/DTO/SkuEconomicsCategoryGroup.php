<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\DTO;

use App\MarketplaceEconomics\Domain\SkuMetrics;

/** Группа отчёта R1 по категории листинга: сумма строк её листингов. */
final readonly class SkuEconomicsCategoryGroup
{
    /**
     * @param string|null $categoryId null — «Без категории»
     * @param list<SkuEconomicsRow> $rows листинги группы в порядке отчёта
     */
    public function __construct(
        public ?string $categoryId,
        public string $name,
        public SkuMetrics $current,
        public SkuMetrics $previous,
        public array $rows,
    ) {
    }
}
