<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Сумма, которую проведение отнесло бы на статью ОПиУ, — в знаке строки документа marketplace_pl
 * (is_negative уже применён). listingId = null: затрата или реализация без листинга либо факт
 * запрошен без разреза по листингам.
 */
final readonly class PlPreviewFactDTO
{
    public function __construct(
        public string $plCategoryId,
        public ?string $listingId,
        public string $amount,
    ) {
    }
}
