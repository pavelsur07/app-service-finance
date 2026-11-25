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
     * @param list<string> $channels каналы с витриной
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
        public array $channels = [],
        /** @var list<SkuEconomicsCategoryGroup>|null null — режим «По SKU» */
        public ?array $groups = null,
        /** Доля выручки строк с категорией, % — только в режиме «По категориям». */
        public ?float $categorizedRevenueShare = null,
    ) {
    }

    public function hasData(): bool
    {
        return [] !== $this->connections;
    }
}
