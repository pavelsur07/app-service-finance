<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Normalizer;

use App\Inventory\Application\DTO\OzonSupplyItem;

/**
 * Страницы снимка `ozon.supplies` (контракт service-snapshot v1) → позиции активных заявок.
 *
 * Порядок страниц задаёт ozon-fetcher: списки заявок (`order_ids`), детали (`orders`), затем состав
 * по одной поставке в порядке возрастания `bundle_id`, каждая до `has_next: false` (`items`).
 * В составе нет `bundle_id` — поставка определяется этим порядком; число групп состава ≠ числу
 * поставок — снимок не разбирается (UnexpectedValueException), а не разносится наугад.
 */
final class OzonSupplyOrdersParser
{
    /** Черновик заявки: товар не отгружен и может не поехать. */
    public const SKIPPED_STATES = ['DATA_FILLING'];

    /**
     * @param list<mixed> $pages
     *
     * @return list<OzonSupplyItem>
     */
    public function parse(array $pages): array
    {
        /** @var array<string, array{orderId: string, state: string, warehouseId: ?string, warehouseName: ?string}> $supplyByBundle */
        $supplyByBundle = [];
        /** @var list<list<array<mixed>>> $groups */
        $groups = [];
        $open = false;

        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                throw new \UnexpectedValueException(sprintf('ozon.supplies: page %d is not an object', $index + 1));
            }
            if (array_key_exists('order_ids', $page)) {
                continue;
            }
            if (array_key_exists('orders', $page)) {
                foreach ($this->listOf($page['orders'], 'orders', $index) as $order) {
                    $orderId = $this->scalar($order['order_id'] ?? null);
                    if (null === $orderId) {
                        throw new \UnexpectedValueException(sprintf('ozon.supplies: order without order_id on page %d', $index + 1));
                    }
                    $state = (string) $this->scalar($order['state'] ?? null);
                    foreach ($this->listOf($order['supplies'] ?? [], 'supplies', $index) as $supply) {
                        $bundleId = $this->scalar($supply['bundle_id'] ?? null);
                        if (null === $bundleId) {
                            continue;
                        }
                        $warehouse = is_array($supply['storage_warehouse'] ?? null) ? $supply['storage_warehouse'] : [];
                        $supplyByBundle[$bundleId] = [
                            'orderId' => $orderId,
                            'state' => $state,
                            'warehouseId' => $this->scalar($warehouse['warehouse_id'] ?? null),
                            'warehouseName' => $this->scalar($warehouse['name'] ?? null),
                        ];
                    }
                }
                continue;
            }
            if (array_key_exists('items', $page)) {
                $rows = $this->listOf($page['items'], 'items', $index);
                if ($open) {
                    $groups[count($groups) - 1] = [...$groups[count($groups) - 1], ...$rows];
                } else {
                    $groups[] = $rows;
                }
                $open = true === ($page['has_next'] ?? false);
                continue;
            }

            throw new \UnexpectedValueException(sprintf('ozon.supplies: unknown page %d', $index + 1));
        }

        $bundles = array_map('strval', array_keys($supplyByBundle));
        sort($bundles, \SORT_STRING);
        if ($open || count($bundles) !== count($groups)) {
            throw new \UnexpectedValueException(sprintf('ozon.supplies: %d supplies but %d bundle groups', count($bundles), count($groups)));
        }

        $items = [];
        foreach ($bundles as $i => $bundleId) {
            $supply = $supplyByBundle[$bundleId];
            if (in_array($supply['state'], self::SKIPPED_STATES, true)) {
                continue;
            }
            foreach ($groups[$i] as $item) {
                $sku = $this->scalar($item['sku'] ?? null);
                $quantity = $this->scalar($item['quantity'] ?? null);
                if (null === $sku || null === $quantity || !is_numeric($quantity) || (float) $quantity < 0) {
                    throw new \UnexpectedValueException(sprintf('ozon.supplies: bundle item without sku or quantity in %s', $bundleId));
                }
                $items[] = new OzonSupplyItem(
                    sku: $sku,
                    offerId: $this->scalar($item['offer_id'] ?? null),
                    quantity: $quantity,
                    orderId: $supply['orderId'],
                    orderState: $supply['state'],
                    warehouseId: $supply['warehouseId'],
                    warehouseName: $supply['warehouseName'],
                );
            }
        }

        return $items;
    }

    /**
     * @return list<array<mixed>>
     */
    private function listOf(mixed $value, string $field, int $index): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException(sprintf('ozon.supplies: "%s" is not an array on page %d', $field, $index + 1));
        }
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException(sprintf('ozon.supplies: "%s" row is not an object on page %d', $field, $index + 1));
            }
        }

        /* @var list<array<mixed>> $value */
        return $value;
    }

    private function scalar(mixed $value): ?string
    {
        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
