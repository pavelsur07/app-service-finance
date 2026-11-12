<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Api\OzonFetcher;

/** Последний загруженный снимок `ozon.supplies` кабинета (service-snapshot v1). */
final readonly class OzonSupplySnapshot
{
    /**
     * @param list<mixed> $pages
     * @param array<string, mixed> $raw ответ целиком — сохраняется как сырьё сессии
     */
    public function __construct(
        public \DateTimeImmutable $periodDate,
        public \DateTimeImmutable $loadedAt,
        public array $pages,
        public array $raw,
    ) {
    }
}
