<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\DTO;

use App\MarketplaceEconomics\Exception\InvalidEconomicsPeriodException;

final readonly class SkuEconomicsFilter
{
    public const MAX_DAYS = 366;
    public const SORTS = ['revenue', 'gross', 'md1', 'md2', 'full', 'ads', 'sales'];
    /** Каналы канона (`MarketplaceType::value`) и их подписи в отчётах. */
    public const CHANNELS = ['ozon' => 'Ozon', 'wildberries' => 'WB'];

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
    ) {
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
        return [] !== $this->tagIds || '' !== $this->search;
    }
}
