<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

/**
 * Карточка товара Ozon из /v3/product/info/list, приведённая к тому, что нужно
 * листингу.
 *
 * `marketplaceSkus` — все SKU товара, а не один. У товара Ozon может быть
 * несколько источников (sds/fbs), у каждого свой sku, и в верхнеуровневое поле
 * попадает только один. Листинг, заведённый финансовым документом по второй
 * схеме, находится только по полному множеству.
 */
final readonly class OzonCatalogItemDTO
{
    /**
     * @param list<string> $marketplaceSkus
     * @param list<string> $barcodes
     * @param array<string, mixed> $marketplaceData
     */
    public function __construct(
        public ?int $productId,
        public string $primarySku,
        public array $marketplaceSkus,
        public ?string $offerId,
        public ?string $name,
        public ?\DateTimeImmutable $marketplaceCreatedAt,
        public array $barcodes,
        public array $marketplaceData,
    ) {
    }
}
