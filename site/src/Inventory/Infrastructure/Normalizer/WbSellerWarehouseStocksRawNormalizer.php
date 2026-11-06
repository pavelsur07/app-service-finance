<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Normalizer;

use App\Inventory\Application\DTO\NormalizedStockRow;
use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Enum\StockStatus;
use App\Inventory\Infrastructure\Api\Wildberries\WbInventoryClient;
use App\Marketplace\Enum\MarketplaceType;

/**
 * Остатки склада продавца WB (FBS): страницы `POST /api/v3/stocks/{warehouseId}`.
 *
 * `sourceSku` — баркод, как его отдаёт WB; `chrtId` не используется, потому что у одного
 * размера бывает несколько баркодов и в уникальном ключе снимка они схлопнулись бы.
 * Листинг для строки находит NormalizeInventorySnapshotAction по баркоду.
 */
final readonly class WbSellerWarehouseStocksRawNormalizer
{
    public const ENDPOINT_PREFIX = WbInventoryClient::SELLER_STOCKS_ENDPOINT.'/';

    /**
     * @param list<InventoryRawSnapshot> $rawSnapshots
     *
     * @return list<NormalizedStockRow>
     */
    public function normalize(array $rawSnapshots): array
    {
        $rows = [];
        foreach ($rawSnapshots as $rawSnapshot) {
            if (!str_starts_with($rawSnapshot->getSourceEndpoint(), self::ENDPOINT_PREFIX)) {
                continue;
            }

            $params = $rawSnapshot->getRequestParams();
            $warehouseId = trim((string) ($params['warehouseId'] ?? ''));
            if ('' === $warehouseId) {
                continue;
            }
            $warehouseName = trim((string) ($params['warehouseName'] ?? ''));

            $stocks = $rawSnapshot->getResponseBody()['stocks'] ?? null;
            if (!is_array($stocks)) {
                continue;
            }

            foreach ($stocks as $stock) {
                if (!is_array($stock)) {
                    continue;
                }
                $barcode = trim((string) ($stock['sku'] ?? ''));
                if ('' === $barcode) {
                    continue;
                }

                $rows[] = new NormalizedStockRow(
                    source: MarketplaceType::WILDBERRIES,
                    sourceSku: $barcode,
                    sourceOfferId: null,
                    fulfillmentType: 'fbs',
                    status: StockStatus::Available,
                    quantity: number_format($this->nonNegativeNumber($stock['amount'] ?? 0), 3, '.', ''),
                    reservedQuantity: '0.000',
                    rawSnapshotId: $rawSnapshot->getId(),
                    locationExternalId: 'seller:'.$warehouseId,
                    locationCode: 'WB-FBS-'.$warehouseId,
                    locationName: '' !== $warehouseName ? $warehouseName : 'WB seller warehouse '.$warehouseId,
                );
            }
        }

        return $rows;
    }

    private function nonNegativeNumber(mixed $value): float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return 0.0;
        }

        return max(0.0, (float) $value);
    }
}
