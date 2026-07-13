<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Normalizer;

use App\Inventory\Application\DTO\NormalizedStockRow;
use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Enum\StockStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\AppLogger;

final readonly class WbWarehouseStocksRawNormalizer
{
    public function __construct(private AppLogger $logger)
    {
    }

    /**
     * @param list<InventoryRawSnapshot> $rawSnapshots
     *
     * @return list<NormalizedStockRow>
     */
    public function normalize(array $rawSnapshots): array
    {
        /** @var array<string, array{nmId: string, warehouseId: string, warehouseName: string, regionName: string, quantity: float, toClient: float, fromClient: float, rawSnapshotId: string}> $groups */
        $groups = [];

        foreach ($rawSnapshots as $rawSnapshot) {
            $items = $rawSnapshot->getResponseBody()['data']['items'] ?? null;
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $nmId = trim((string) ($item['nmId'] ?? ''));
                $warehouseId = trim((string) ($item['warehouseId'] ?? ''));
                if ('' === $nmId || '' === $warehouseId) {
                    $this->logger->warning('WB inventory row skipped: required identifiers are missing.', [
                        'rawSnapshotId' => $rawSnapshot->getId(),
                        'hasNmId' => '' !== $nmId,
                        'hasWarehouseId' => '' !== $warehouseId,
                    ]);
                    continue;
                }

                $key = $nmId."\0".$warehouseId;
                $groups[$key] ??= [
                    'nmId' => $nmId,
                    'warehouseId' => $warehouseId,
                    'warehouseName' => trim((string) ($item['warehouseName'] ?? '')),
                    'regionName' => trim((string) ($item['regionName'] ?? '')),
                    'quantity' => 0.0,
                    'toClient' => 0.0,
                    'fromClient' => 0.0,
                    'rawSnapshotId' => $rawSnapshot->getId(),
                ];

                $groups[$key]['quantity'] += $this->nonNegativeNumber($item['quantity'] ?? 0);
                $groups[$key]['toClient'] += $this->nonNegativeNumber($item['inWayToClient'] ?? 0);
                $groups[$key]['fromClient'] += $this->nonNegativeNumber($item['inWayFromClient'] ?? 0);
                $groups[$key]['rawSnapshotId'] = $rawSnapshot->getId();

                if ('' !== trim((string) ($item['warehouseName'] ?? ''))) {
                    $groups[$key]['warehouseName'] = trim((string) $item['warehouseName']);
                }
                if ('' !== trim((string) ($item['regionName'] ?? ''))) {
                    $groups[$key]['regionName'] = trim((string) $item['regionName']);
                }
            }
        }

        $rows = [];
        foreach ($groups as $group) {
            $locationName = '' !== $group['warehouseName'] ? $group['warehouseName'] : 'WB warehouse '.$group['warehouseId'];
            $metadata = '' !== $group['regionName'] ? ['regionName' => $group['regionName']] : null;

            foreach ([
                StockStatus::Available->value => $group['quantity'],
                StockStatus::InTransitToCustomer->value => $group['toClient'],
                StockStatus::InTransitFromCustomer->value => $group['fromClient'],
            ] as $status => $quantity) {
                $rows[] = new NormalizedStockRow(
                    source: MarketplaceType::WILDBERRIES,
                    sourceSku: $group['nmId'],
                    sourceOfferId: null,
                    fulfillmentType: 'fbw',
                    status: StockStatus::from($status),
                    quantity: number_format($quantity, 3, '.', ''),
                    reservedQuantity: '0.000',
                    rawSnapshotId: $group['rawSnapshotId'],
                    locationExternalId: $group['warehouseId'],
                    locationCode: 'WB-'.$group['warehouseId'],
                    locationName: $locationName,
                    locationMetadata: $metadata,
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
