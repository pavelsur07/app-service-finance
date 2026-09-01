<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\MappedOrder;
use App\Ingestion\Application\DTO\MappedOrderItem;
use App\Ingestion\Domain\Contract\OrderMapperInterface;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestSource;

/**
 * Отправления Ozon (FBO и FBS) → нормализованные заказы.
 *
 * Естественный ключ — `posting_number`, а не `order_id`: в выгрузке 2026-09-01
 * 100 отправлений приходились на 89 заказов, и статусами живёт именно
 * отправление. `order_number` сохраняется рядом, чтобы связь с заказом не
 * терялась.
 */
final class OzonOrderMapper implements OrderMapperInterface
{
    public function source(): IngestSource
    {
        return IngestSource::OZON;
    }

    public function resourceTypes(): array
    {
        return [OzonResourceType::ORDERS_FBO, OzonResourceType::ORDERS_FBS];
    }

    public function map(IngestRawRecord $rawRecord, iterable $rows): array
    {
        // Схема берётся из типа ресурса, а не из payload'а: у FBO и FBS разные
        // эндпоинты и разные словари статусов, и определять её по содержимому
        // значило бы угадывать.
        $scheme = OzonResourceType::ORDERS_FBS === $rawRecord->getResourceType()
            ? IngestOrderScheme::FBS
            : IngestOrderScheme::FBO;

        $orders = [];
        foreach ($rows as $row) {
            $postingNumber = $this->stringOrNull($row['posting_number'] ?? null);
            $status = $this->stringOrNull($row['status'] ?? null);
            if (null === $postingNumber || null === $status) {
                continue;
            }

            $orders[] = new MappedOrder(
                externalId: $postingNumber,
                scheme: $scheme,
                orderedAt: $this->parseDate($row['created_at'] ?? null) ?? $rawRecord->getFetchedAt(),
                rawStatus: $status,
                items: $this->mapItems($row),
                externalOrderId: $this->stringOrNull($row['order_number'] ?? null),
                rawSubstatus: $this->stringOrNull($row['substatus'] ?? null),
                attributes: $this->mapAttributes($row),
            );
        }

        return $orders;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<MappedOrderItem>
     */
    private function mapItems(array $row): array
    {
        $products = $row['products'] ?? [];
        if (!is_array($products)) {
            return [];
        }

        $items = [];
        $lineNo = 0;
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $sku = $this->stringOrNull($product['sku'] ?? null);
            $offerId = $this->stringOrNull($product['offer_id'] ?? null);

            $items[] = new MappedOrderItem(
                // lineNo — позиция в исходном массиве. Это часть ключа
                // идемпотентности, поэтому он не должен зависеть от содержимого.
                lineNo: $lineNo,
                quantity: is_numeric($product['quantity'] ?? null) ? (int) $product['quantity'] : 0,
                externalSku: $sku,
                offerId: $offerId,
                name: $this->stringOrNull($product['name'] ?? null),
                priceMinor: $this->toMinor($product['price'] ?? null),
                currency: $this->stringOrNull($product['currency_code'] ?? null),
                marketplaceBuyout: (bool) ($product['is_marketplace_buyout'] ?? false),
                // Ровно те ключи, которые читает OzonListingResolver.
                sourceData: array_filter([
                    'sku' => $sku,
                    'offer_id' => $offerId,
                    'name' => $this->stringOrNull($product['name'] ?? null),
                ], static fn (mixed $v): bool => null !== $v),
            );
            ++$lineNo;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapAttributes(array $row): array
    {
        $analytics = $row['analytics_data'] ?? null;
        $analytics = is_array($analytics) ? $analytics : [];

        return array_filter([
            'order_id' => isset($row['order_id']) && is_int($row['order_id']) ? $row['order_id'] : null,
            'cancel_reason_id' => isset($row['cancel_reason_id']) && is_int($row['cancel_reason_id']) ? $row['cancel_reason_id'] : null,
            'delivery_type' => $this->stringOrNull($analytics['delivery_type'] ?? null),
            'warehouse_id' => isset($analytics['warehouse_id']) && is_int($analytics['warehouse_id']) ? $analytics['warehouse_id'] : null,
        ], static fn (mixed $v): bool => null !== $v);
    }

    /**
     * Рубли строкой → копейки строкой. Через float нельзя: денежная арифметика
     * в плавающей точке даёт расхождения на больших объёмах.
     */
    private function toMinor(mixed $price): ?string
    {
        if (!is_string($price) && !is_int($price) && !is_float($price)) {
            return null;
        }

        $raw = trim((string) $price);
        if (1 !== preg_match('/^(-?)(\d+)(?:[.,](\d{1,2}))?$/', $raw, $m)) {
            return null;
        }

        $fraction = str_pad($m[3] ?? '', 2, '0');
        $digits = ltrim($m[2].$fraction, '0');

        return $m[1].('' === $digits ? '0' : $digits);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
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
