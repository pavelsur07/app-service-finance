<?php

declare(strict_types=1);

namespace App\Inventory\Application\DTO;

/**
 * Остатки на дату отчёта вместе с происхождением данных.
 *
 * Карты отделены намеренно: отсутствие листинга в $qtyByListingId означает
 * «значение неизвестно», а не «ноль». Отличить одно от другого можно только
 * зная, из какого снапшота собран результат и какие источники отброшены как
 * протухшие, — поэтому эти сведения входят в контракт, а не остаются внутри
 * запроса.
 */
final readonly class StockOnDateResult
{
    /**
     * @param array<string, float> $qtyByListingId listingId => остаток, шт
     * @param array<string, string> $snapshotDateBySource marketplace => дата снапшота (Y-m-d), из которого взяты данные
     * @param array<string, string> $staleSources marketplace => дата последнего снапшота (Y-m-d), отброшенного как протухший
     */
    public function __construct(
        public array $qtyByListingId,
        public array $snapshotDateBySource,
        public array $staleSources,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], []);
    }
}
