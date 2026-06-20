<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

final readonly class OzonAccrualByDayRawAggregator
{
    public function __construct(private OzonMoneyParser $moneyParser)
    {
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     */
    public function aggregate(iterable $rows): OzonAccrualByDayRawAggregate
    {
        $scannedRows = 0;
        $dateCategory = [];
        $deliveryServices = [];
        $commissions = [];
        $itemFees = [];
        $nonItemFees = [];
        $containerFees = [];

        foreach ($rows as $row) {
            ++$scannedRows;

            $date = $this->stringValue($row['date'] ?? 'unknown');
            $category = $this->stringValue($row['accrued_category'] ?? 'unknown');

            $this->add($dateCategory, [$date, $category], [
                'date' => $date,
                'category' => $category,
            ], $this->moneyObjectMinor($row['total_amount'] ?? null));

            $this->collectPosting($row['posting'] ?? null, $date, $deliveryServices, $commissions);
            $this->collectItemFees($row['item_fees'] ?? null, $date, $itemFees);
            $this->collectNonItemFee($row['non_item_fee'] ?? null, $date, $nonItemFees);
            $this->collectContainerFees($row['container_fees'] ?? null, $date, $containerFees);
        }

        return new OzonAccrualByDayRawAggregate(
            scannedRows: $scannedRows,
            dateCategoryRows: $this->flatten($dateCategory),
            deliveryServiceRows: $this->flatten($deliveryServices),
            commissionRows: $this->flatten($commissions),
            itemFeeRows: $this->flatten($itemFees),
            nonItemFeeRows: $this->flatten($nonItemFees),
            containerFeeRows: $this->flatten($containerFees),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, int|string>> $deliveryServices
     * @param array<string, array<string, int|string>> $commissions
     */
    private function collectPosting(mixed $row, string $date, array &$deliveryServices, array &$commissions): void
    {
        if (!is_array($row)) {
            return;
        }

        $products = $row['products'] ?? [];
        if (!is_array($products)) {
            return;
        }

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $this->collectDeliveryServices($product['delivery']['services'] ?? null, $date, $deliveryServices);
            $this->collectCommission($product['commission'] ?? null, $date, $commissions);
        }
    }

    /**
     * @param array<string, array<string, int|string>> $deliveryServices
     */
    private function collectDeliveryServices(mixed $services, string $date, array &$deliveryServices): void
    {
        if (!is_array($services)) {
            return;
        }

        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }

            $typeId = $this->typeId($service);
            $amountMinor = $this->moneyObjectMinor($service['accrued'] ?? null);
            if (0 === $amountMinor) {
                continue;
            }

            $this->add($deliveryServices, [$date, $typeId], [
                'date' => $date,
                'typeId' => $typeId,
            ], $amountMinor);
        }
    }

    /**
     * @param array<string, array<string, int|string>> $commissions
     */
    private function collectCommission(mixed $commission, string $date, array &$commissions): void
    {
        if (!is_array($commission)) {
            return;
        }

        foreach ($commission as $field => $value) {
            if (!is_array($value) || !array_key_exists('amount', $value)) {
                continue;
            }

            $amountMinor = $this->moneyObjectMinor($value);
            if (0 === $amountMinor) {
                continue;
            }

            $field = (string) $field;
            $this->add($commissions, [$date, $field], [
                'date' => $date,
                'field' => $field,
            ], $amountMinor);
        }
    }

    /**
     * @param array<string, array<string, int|string>> $itemFees
     */
    private function collectItemFees(mixed $itemFeesBlock, string $date, array &$itemFees): void
    {
        if (!is_array($itemFeesBlock)) {
            return;
        }

        $skuFeeGroups = $itemFeesBlock['fees'] ?? [];
        if (!is_array($skuFeeGroups)) {
            return;
        }

        foreach ($skuFeeGroups as $skuFeeGroup) {
            if (!is_array($skuFeeGroup) || !is_array($skuFeeGroup['fees'] ?? null)) {
                continue;
            }

            foreach ($skuFeeGroup['fees'] as $fee) {
                $this->collectTypedFee($fee, $date, $itemFees);
            }
        }
    }

    /**
     * @param array<string, array<string, int|string>> $nonItemFees
     */
    private function collectNonItemFee(mixed $fee, string $date, array &$nonItemFees): void
    {
        $this->collectTypedFee($fee, $date, $nonItemFees);
    }

    /**
     * @param array<string, array<string, int|string>> $containerFees
     */
    private function collectContainerFees(mixed $value, string $date, array &$containerFees): void
    {
        if (!is_array($value)) {
            return;
        }

        $this->collectTypedFee($value, $date, $containerFees);

        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectContainerFees($child, $date, $containerFees);
            }
        }
    }

    /**
     * @param array<string, array<string, int|string>> $bucket
     */
    private function collectTypedFee(mixed $fee, string $date, array &$bucket): void
    {
        if (!is_array($fee) || !array_key_exists('accrued', $fee)) {
            return;
        }

        $typeId = $this->typeId($fee);
        $amountMinor = $this->moneyObjectMinor($fee['accrued']);
        if (0 === $amountMinor) {
            return;
        }

        $this->add($bucket, [$date, $typeId], [
            'date' => $date,
            'typeId' => $typeId,
        ], $amountMinor);
    }

    /**
     * @param array<string, array<string, int|string>> $bucket
     * @param list<string> $keyParts
     * @param array<string, string> $dimensions
     */
    private function add(array &$bucket, array $keyParts, array $dimensions, int $amountMinor): void
    {
        $key = implode('|', $keyParts);
        if (!isset($bucket[$key])) {
            $bucket[$key] = $dimensions + [
                'count' => 0,
                'totalMinor' => 0,
            ];
        }

        $bucket[$key]['count'] = (int) $bucket[$key]['count'] + 1;
        $bucket[$key]['totalMinor'] = (int) $bucket[$key]['totalMinor'] + $amountMinor;
    }

    /**
     * @param array<string, array<string, int|string>> $bucket
     *
     * @return list<array<string, int|string>>
     */
    private function flatten(array $bucket): array
    {
        $rows = array_values($bucket);
        usort($rows, static function (array $left, array $right): int {
            foreach (['date', 'category', 'typeId', 'field'] as $key) {
                $comparison = strcmp((string) ($left[$key] ?? ''), (string) ($right[$key] ?? ''));
                if (0 !== $comparison) {
                    return $comparison;
                }
            }

            return 0;
        });

        return $rows;
    }

    /**
     * @param array<string, mixed> $fee
     */
    private function typeId(array $fee): string
    {
        $typeId = $fee['type_id'] ?? $fee['typeId'] ?? 'unknown';

        return $this->stringValue($typeId);
    }

    private function moneyObjectMinor(mixed $value): int
    {
        if (is_array($value) && array_key_exists('amount', $value)) {
            return $this->moneyParser->minor($value['amount']);
        }

        return $this->moneyParser->minor($value);
    }

    private function stringValue(mixed $value): string
    {
        $value = trim((string) $value);

        return '' === $value ? 'unknown' : $value;
    }
}
