<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\DTO;

use App\MarketplaceEconomics\Domain\SkuMetrics;

/** Отчёт R1: строки SKU, нераспределённое, итог кабинета за период и прошлый период той же длины. */
final readonly class SkuEconomicsReport
{
    /**
     * @param list<SkuEconomicsRow> $rows отфильтрованы и отсортированы, без пагинации
     * @param list<string> $connections кабинеты с витриной
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public \DateTimeImmutable $previousFrom,
        public \DateTimeImmutable $previousTo,
        public array $rows,
        /** null — при фильтре по тегам или поиску нераспределённое не показывается. */
        public ?SkuMetrics $unallocated,
        public SkuMetrics $total,
        public SkuMetrics $previousTotal,
        public array $connections,
        public int $days,
        public int $daysWithoutCostPrice,
    ) {
    }

    public function hasData(): bool
    {
        return [] !== $this->connections;
    }
}
