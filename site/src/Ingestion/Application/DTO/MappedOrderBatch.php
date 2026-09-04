<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Результат разбора сырой страницы заказов.
 *
 * Отдельный DTO вместо голого list<MappedOrder> нужен ради второго списка.
 * Строка, которую маппер не смог разобрать, раньше просто исчезала: курсор
 * при этом уезжал вперёд, и потеря становилась постоянной, ничем не отличимой
 * от «заказов в окне не было». Отброшенные строки обязаны быть видимой
 * очередью на разбор, а не тишиной.
 */
final readonly class MappedOrderBatch
{
    /**
     * @param list<MappedOrder> $orders
     * @param list<array{reason: string, hint: ?string, value?: ?string}> $skipped пропущенные строки: причина, номер и (если есть) отвергнутое значение
     */
    public function __construct(
        public array $orders = [],
        public array $skipped = [],
    ) {
    }
}
