<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Normalizer\Ozon;

use App\Marketplace\DTO\OzonCatalogItemDTO;

/**
 * Ответ Ozon /v3/product/info/list → плоские карточки для листингов.
 *
 * Чистая функция без зависимостей: тестируется на фикстуре реального ответа
 * (`tests/Fixtures/Marketplace/Ozon/product_info_list.json`).
 */
final class OzonProductCatalogNormalizer
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return list<OzonCatalogItemDTO>
     */
    public function normalize(array $payload): array
    {
        $items = $payload['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $skus = $this->collectSkus($item);
            if ([] === $skus) {
                continue;
            }

            $result[] = new OzonCatalogItemDTO(
                productId: is_int($item['id'] ?? null) && $item['id'] > 0 ? $item['id'] : null,
                primarySku: $skus[0],
                marketplaceSkus: $skus,
                offerId: $this->nullableString($item['offer_id'] ?? null),
                name: $this->nullableString($item['name'] ?? null),
                marketplaceCreatedAt: $this->parseDate($item['created_at'] ?? null),
                barcodes: $this->collectBarcodes($item),
                marketplaceData: $this->collectMarketplaceData($item),
            );
        }

        return $result;
    }

    /**
     * Идентификаторы товаров со страницы /v3/product/list — вход для
     * /v3/product/info/list. Именно этот эндпоинт видит товары без продаж.
     *
     * @param array<string, mixed> $page
     *
     * Дубликаты НЕ схлопываются: вызывающий сравнивает число полученных id
     * с числом элементов страницы, чтобы заметить нарушение контракта, и
     * схлопнутый дубль читался бы как пропущенный товар. Дедупликация —
     * на стороне вызывающего.
     *
     * @return list<int>
     */
    public function extractProductIds(array $page): array
    {
        $result = $page['result'] ?? [];
        $items = is_array($result) ? ($result['items'] ?? []) : [];
        if (!is_array($items)) {
            return [];
        }

        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = $item['product_id'] ?? null;
            if (is_int($productId) && $productId > 0) {
                $ids[] = $productId;
            }
        }

        return $ids;
    }

    /**
     * Верхнеуровневый sku первым, затем sources[].sku. Дубли схлопываются:
     * верхнеуровневый sku всегда присутствует и в sources.
     *
     * @param array<string, mixed> $item
     *
     * @return list<string>
     */
    private function collectSkus(array $item): array
    {
        $skus = [];

        $topLevel = $this->normalizeSku($item['sku'] ?? null);
        if (null !== $topLevel) {
            $skus[] = $topLevel;
        }

        $sources = $item['sources'] ?? [];
        if (is_array($sources)) {
            foreach ($sources as $source) {
                if (!is_array($source)) {
                    continue;
                }
                $sku = $this->normalizeSku($source['sku'] ?? null);
                if (null !== $sku) {
                    $skus[] = $sku;
                }
            }
        }

        return array_values(array_unique($skus));
    }

    private function normalizeSku(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }

        $sku = trim((string) $value);

        return '' === $sku || '0' === $sku ? null : $sku;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return list<string>
     */
    private function collectBarcodes(array $item): array
    {
        $barcodes = $item['barcodes'] ?? [];
        if (!is_array($barcodes)) {
            return [];
        }

        $result = [];
        foreach ($barcodes as $barcode) {
            if (!is_string($barcode) && !is_int($barcode)) {
                continue;
            }
            $value = trim((string) $barcode);
            if ('' !== $value) {
                $result[] = $value;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Цена каталога — витринная, а не цена продажи, поэтому она идёт в JSON,
     * а не в колонку `price`: иначе поле сменило бы смысл.
     *
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function collectMarketplaceData(array $item): array
    {
        $statuses = $item['statuses'] ?? [];
        $statuses = is_array($statuses) ? $statuses : [];

        $primaryImage = $item['primary_image'] ?? [];
        $primaryImage = is_array($primaryImage) ? ($primaryImage[0] ?? null) : $primaryImage;

        return array_filter([
            'price' => $this->nullableString($item['price'] ?? null),
            'old_price' => $this->nullableString($item['old_price'] ?? null),
            'min_price' => $this->nullableString($item['min_price'] ?? null),
            'currency_code' => $this->nullableString($item['currency_code'] ?? null),
            'status' => $this->nullableString($statuses['status'] ?? null),
            'status_name' => $this->nullableString($statuses['status_name'] ?? null),
            'is_archived' => (bool) ($item['is_archived'] ?? false),
            'primary_image' => $this->nullableString($primaryImage),
            'description_category_id' => isset($item['description_category_id']) && is_int($item['description_category_id'])
                ? $item['description_category_id']
                : null,
        ], static fn (mixed $value): bool => null !== $value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $string = trim((string) $value);

        return '' === $string ? null : $string;
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
