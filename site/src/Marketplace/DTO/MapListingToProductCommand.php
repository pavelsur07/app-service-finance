<?php

namespace App\Marketplace\DTO;

/**
 * Команда на связывание листинга маркетплейса с продуктом
 */
final class MapListingToProductCommand
{
    public function __construct(
        public readonly string $companyId,      // ← SCALAR! Не Entity
        public readonly string $actorUserId,     // ← SCALAR! Не Entity
        public readonly string $listingId,       // ← ID листинга
        public readonly string $productId,       // ← ID продукта
    ) {
    }
}
