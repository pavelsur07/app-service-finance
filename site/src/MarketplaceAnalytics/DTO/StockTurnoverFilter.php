<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\DTO;

use App\Inventory\Enum\FulfillmentScheme;
use App\MarketplaceAnalytics\Enum\TurnoverBucket;
use Webmozart\Assert\Assert;

/**
 * Фильтр отчёта «Оборачиваемость». Окно продаж — periodDays дней, заканчивая date
 * включительно. bucket сужает только строки, сводка считается без него.
 */
final readonly class StockTurnoverFilter
{
    public const PERIODS = [30, 60, 90];

    /**
     * @param list<string> $tagIds
     */
    public function __construct(
        public string $companyId,
        public \DateTimeImmutable $date,
        public int $periodDays,
        public ?string $source,
        public ?FulfillmentScheme $scheme,
        public ?TurnoverBucket $bucket,
        public array $tagIds,
    ) {
        Assert::uuid($companyId);
        Assert::inArray($periodDays, self::PERIODS);
    }

    public function salesFrom(): \DateTimeImmutable
    {
        return $this->date->modify(sprintf('-%d days', $this->periodDays - 1));
    }
}
