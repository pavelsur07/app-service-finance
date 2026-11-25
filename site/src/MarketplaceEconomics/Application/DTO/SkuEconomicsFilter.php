<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\DTO;

use App\MarketplaceEconomics\Exception\InvalidEconomicsPeriodException;
use Ramsey\Uuid\Uuid;

final readonly class SkuEconomicsFilter
{
    public const MAX_DAYS = 366;
    public const SORTS = ['revenue', 'gross', 'md1', 'md2', 'full', 'ads', 'sales'];
    /** Каналы канона (`MarketplaceType::value`) и их подписи в отчётах. */
    public const CHANNELS = ['ozon' => 'Ozon', 'wildberries' => 'WB'];
    /** Значение фильтра категории «листинги без категории». */
    public const CATEGORY_NONE = 'none';

    /**
     * @param list<string> $tagIds
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?string $connectionRef = null,
        public array $tagIds = [],
        public string $search = '',
        public string $sort = 'md2',
        public bool $desc = true,
        public ?string $channel = null,
        /** false — «Без разнесения»: без строк разнесения витрины, затраты без SKU остаются в «Нераспределено». */
        public bool $allocated = true,
        /** uuid категории листинга, `none` — без категории, null — все. */
        public ?string $category = null,
        /** true — строки отчёта сгруппированы по категориям листингов. */
        public bool $byCategory = false,
    ) {
        if (null !== $category && self::CATEGORY_NONE !== $category && !Uuid::isValid($category)) {
            throw new InvalidEconomicsPeriodException('Неизвестная категория.');
        }
        if (null !== $channel && !isset(self::CHANNELS[$channel])) {
            throw new InvalidEconomicsPeriodException('Неизвестный канал.');
        }
        if ($from > $to) {
            throw new InvalidEconomicsPeriodException('Начало периода позже конца.');
        }
        if ((int) $from->diff($to)->days + 1 > self::MAX_DAYS) {
            throw new InvalidEconomicsPeriodException(sprintf('Период — не больше %d дней.', self::MAX_DAYS));
        }
    }

    public function filtersListings(): bool
    {
        return [] !== $this->tagIds || '' !== $this->search || null !== $this->category;
    }
}
