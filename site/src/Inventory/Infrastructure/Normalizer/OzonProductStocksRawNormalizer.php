<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Normalizer;

use App\Inventory\Application\DTO\NormalizedStockRow;
use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Enum\StockStatus;
use App\Marketplace\Enum\MarketplaceType;

final class OzonProductStocksRawNormalizer
{
    /**
     * @param array<string, mixed>|InventoryRawSnapshot $raw
     *
     * @return list<NormalizedStockRow>
     */
    public function normalize(array|InventoryRawSnapshot $raw): array
    {
        $payload = $raw instanceof InventoryRawSnapshot ? $raw->getResponseBody() : $raw;
        $rawSnapshotId = $raw instanceof InventoryRawSnapshot ? $raw->getId() : '';

        $items = $this->extractItems($payload);
        if ($items === []) {
            return [];
        }

        $rows = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $stocks = $item['stocks'] ?? null;
            if (!is_array($stocks) || $stocks === []) {
                continue;
            }

            $offerId = isset($item['offer_id']) ? (string) $item['offer_id'] : null;

            foreach ($stocks as $stock) {
                if (!is_array($stock) || !array_key_exists('sku', $stock)) {
                    continue;
                }

                $sourceSku = trim((string) $stock['sku']);
                if ($sourceSku === '') {
                    continue;
                }

                $rows[] = new NormalizedStockRow(
                    source: MarketplaceType::OZON,
                    sourceSku: $sourceSku,
                    sourceOfferId: $offerId,
                    fulfillmentType: isset($stock['type']) ? (string) $stock['type'] : null,
                    status: StockStatus::Available,
                    quantity: $this->toDecimalString($stock['present'] ?? 0),
                    reservedQuantity: $this->toDecimalString($stock['reserved'] ?? 0),
                    rawSnapshotId: $rawSnapshotId,
                );
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<mixed>
     */
    private function extractItems(array $payload): array
    {
        $topLevelItems = $payload['items'] ?? null;
        if (is_array($topLevelItems)) {
            return array_values($topLevelItems);
        }

        $result = $payload['result'] ?? null;
        if (!is_array($result)) {
            return [];
        }

        $resultItems = $result['items'] ?? null;
        if (!is_array($resultItems)) {
            return [];
        }

        return array_values($resultItems);
    }

    private function toDecimalString(mixed $value): string
    {
        if (is_int($value) || is_float($value) || is_string($value)) {
            return number_format((float) $value, 3, '.', '');
        }

        return '0.000';
    }
}
