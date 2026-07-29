<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Infrastructure\Query\WbRawFinancialReportProductQuery;
use App\Shared\Domain\ValueObject\Money;

/**
 * Resolves raw WB product aggregates to listing variants and historical costs.
 */
final readonly class WbRawFinancialReportProductEnricher
{
    private const CURRENCY = 'RUB';

    public function __construct(
        private WbRawFinancialReportProductQuery $productQuery,
    ) {
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    public function enrich(string $companyId, array $report): array
    {
        $sources = is_array($report['_product_sources'] ?? null) ? $report['_product_sources'] : [];
        if ([] === $sources) {
            $report['products'] = [];
            $report['product_summary'] = $this->emptySummary(
                (int) ($report['summary']['for_pay_minor'] ?? 0),
                (int) ($report['summary']['sale_without_spp_minor'] ?? 0),
            );
            unset($report['_product_sources']);

            return $report;
        }

        [$nmIds, $barcodes] = $this->identifiers($sources);
        $context = $this->catalogContext(
            $this->productQuery->findByCompanyAndIdentifiers($companyId, $nmIds, $barcodes),
        );

        $products = [];
        foreach (array_values($sources) as $index => $source) {
            if (!is_array($source)) {
                continue;
            }

            [$mappingStatus, $listingId] = $this->matchListing($source, $context);
            $key = null === $listingId ? 'raw:'.$index : 'listing:'.$listingId;
            $listing = null === $listingId ? null : $context['listings'][$listingId];
            $products[$key] ??= $this->newProduct($source, $mappingStatus, $listing);
            $this->mergeSource($products[$key], $source);
        }

        $rows = [];
        foreach ($products as $product) {
            $rows[] = $this->finalizeProduct($product, $context);
        }
        usort($rows, static function (array $left, array $right): int {
            $activityComparison = \bccomp(
                \bcadd((string) $right['sold_quantity'], (string) $right['returned_quantity'], 0),
                \bcadd((string) $left['sold_quantity'], (string) $left['returned_quantity'], 0),
                0,
            );

            if (0 !== $activityComparison) {
                return $activityComparison;
            }

            return strnatcasecmp((string) $left['supplier_sku'], (string) $right['supplier_sku'])
                ?: strnatcasecmp((string) $left['nm_id'], (string) $right['nm_id'])
                ?: strnatcasecmp((string) $left['size'], (string) $right['size']);
        });

        $report['products'] = $rows;
        $report['product_summary'] = $this->summary(
            $rows,
            (int) ($report['summary']['for_pay_minor'] ?? 0),
            (int) ($report['summary']['sale_without_spp_minor'] ?? 0),
        );
        unset($report['_product_sources']);

        return $report;
    }

    /**
     * @param list<array<string, mixed>> $sources
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function identifiers(array $sources): array
    {
        $nmIds = [];
        $barcodes = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $nmId = trim((string) ($source['nm_id'] ?? ''));
            if ('' !== $nmId && '0' !== $nmId) {
                $nmIds[$nmId] = true;
            }
            foreach ((array) ($source['barcodes'] ?? []) as $barcode) {
                $barcode = trim((string) $barcode);
                if ('' !== $barcode) {
                    $barcodes[$barcode] = true;
                }
            }
        }

        return [array_keys($nmIds), array_keys($barcodes)];
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return array{
     *     listings: array<string, array<string, mixed>>,
     *     by_nm_size: array<string, array<string, true>>,
     *     by_barcode: array<string, array<string, true>>
     * }
     */
    private function catalogContext(iterable $rows): array
    {
        $listings = [];
        foreach ($rows as $row) {
            $listingId = (string) ($row['listing_id'] ?? '');
            if ('' === $listingId) {
                continue;
            }

            $listings[$listingId] ??= [
                'id' => $listingId,
                'nm_id' => (string) ($row['nm_id'] ?? ''),
                'supplier_sku' => (string) ($row['supplier_sku'] ?? ''),
                'size' => $this->normalizeWbSize((string) ($row['size'] ?? '')),
                'name' => (string) ($row['name'] ?? ''),
                'barcodes' => [],
                'costs' => [],
            ];

            $barcode = trim((string) ($row['barcode'] ?? ''));
            if ('' !== $barcode) {
                $listings[$listingId]['barcodes'][$barcode] = true;
            }

            $costId = trim((string) ($row['cost_id'] ?? ''));
            if ('' !== $costId) {
                $listings[$listingId]['costs'][$costId] = [
                    'effective_from' => substr((string) ($row['effective_from'] ?? ''), 0, 10),
                    'effective_to' => null === ($row['effective_to'] ?? null)
                        ? null
                        : substr((string) $row['effective_to'], 0, 10),
                    'price_amount' => (string) ($row['price_amount'] ?? ''),
                    'price_currency' => strtoupper((string) ($row['price_currency'] ?? '')),
                ];
            }
        }

        $byNmSize = [];
        $byBarcode = [];
        foreach ($listings as $listingId => &$listing) {
            $listing['barcodes'] = array_map('strval', array_keys($listing['barcodes']));
            $listing['costs'] = array_values($listing['costs']);
            usort(
                $listing['costs'],
                static fn (array $left, array $right): int => strcmp($left['effective_from'], $right['effective_from']),
            );

            $nmId = trim((string) $listing['nm_id']);
            if ('' !== $nmId && '0' !== $nmId) {
                $byNmSize[$nmId."\0".$listing['size']][$listingId] = true;
            }
            foreach ($listing['barcodes'] as $barcode) {
                $byBarcode[$barcode][$listingId] = true;
            }
        }
        unset($listing);

        return [
            'listings' => $listings,
            'by_nm_size' => $byNmSize,
            'by_barcode' => $byBarcode,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array{
     *     listings: array<string, array<string, mixed>>,
     *     by_nm_size: array<string, array<string, true>>,
     *     by_barcode: array<string, array<string, true>>
     * } $context
     *
     * @return array{0: 'mapped'|'unmapped'|'conflict', 1: string|null}
     */
    private function matchListing(array $source, array $context): array
    {
        $candidates = [];
        $nmId = trim((string) ($source['nm_id'] ?? ''));
        if ('' !== $nmId && '0' !== $nmId) {
            $key = $nmId."\0".$this->normalizeWbSize((string) ($source['size'] ?? ''));
            foreach ($context['by_nm_size'][$key] ?? [] as $listingId => $_) {
                $candidates[$listingId] = true;
            }
        }
        foreach ((array) ($source['barcodes'] ?? []) as $barcode) {
            $barcode = trim((string) $barcode);
            foreach ($context['by_barcode'][$barcode] ?? [] as $listingId => $_) {
                $candidates[$listingId] = true;
            }
        }

        $listingIds = array_keys($candidates);

        return match (count($listingIds)) {
            0 => ['unmapped', null],
            1 => ['mapped', $listingIds[0]],
            default => ['conflict', null],
        };
    }

    /**
     * @param array<string, mixed> $source
     * @param 'mapped'|'unmapped'|'conflict' $mappingStatus
     * @param array<string, mixed>|null $listing
     *
     * @return array<string, mixed>
     */
    private function newProduct(array $source, string $mappingStatus, ?array $listing): array
    {
        $barcodes = [];
        foreach ([...(array) ($source['barcodes'] ?? []), ...(array) ($listing['barcodes'] ?? [])] as $barcode) {
            $barcode = trim((string) $barcode);
            if ('' !== $barcode) {
                $barcodes[$barcode] = true;
            }
        }

        return [
            'listing_id' => $listing['id'] ?? null,
            'mapping_status' => $mappingStatus,
            'name' => $this->productName($source, $listing),
            'nm_id' => $this->firstNonEmpty(
                '0' === (string) ($listing['nm_id'] ?? '') ? '' : (string) ($listing['nm_id'] ?? ''),
                '0' === (string) ($source['nm_id'] ?? '') ? '' : (string) ($source['nm_id'] ?? ''),
            ),
            'supplier_sku' => $this->firstNonEmpty(
                (string) ($listing['supplier_sku'] ?? ''),
                (string) ($source['supplier_sku'] ?? ''),
            ),
            'size' => $this->firstNonEmpty(
                (string) ($listing['size'] ?? ''),
                (string) ($source['size'] ?? ''),
                'UNKNOWN',
            ),
            'barcodes' => $barcodes,
            'sold_quantity' => 0,
            'returned_quantity' => 0,
            'sales_without_spp_minor' => 0,
            'returns_without_spp_minor' => 0,
            'sales_with_spp_minor' => 0,
            'returns_with_spp_minor' => 0,
            'sales_for_pay_minor' => 0,
            'returns_for_pay_minor' => 0,
            '_cost_quantities' => [
                'sale' => [],
                'return' => [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $source
     */
    private function mergeSource(array &$product, array $source): void
    {
        foreach ((array) ($source['barcodes'] ?? []) as $barcode) {
            $barcode = trim((string) $barcode);
            if ('' !== $barcode) {
                $product['barcodes'][$barcode] = true;
            }
        }
        if ('' === trim((string) $product['supplier_sku'])) {
            $product['supplier_sku'] = trim((string) ($source['supplier_sku'] ?? ''));
        }
        if ('Без названия' === $product['name']) {
            $product['name'] = $this->productName($source, null);
        }

        foreach (['sold_quantity', 'returned_quantity'] as $field) {
            $product[$field] = $this->sumQuantity((int) $product[$field], (int) ($source[$field] ?? 0));
        }
        foreach ([
            'sales_without_spp_minor',
            'returns_without_spp_minor',
            'sales_with_spp_minor',
            'returns_with_spp_minor',
            'sales_for_pay_minor',
            'returns_for_pay_minor',
        ] as $field) {
            $product[$field] = $this->sumMoney((int) $product[$field], (int) ($source[$field] ?? 0));
        }
        foreach (['sale', 'return'] as $direction) {
            foreach ((array) ($source['_cost_quantities'][$direction] ?? []) as $date => $quantity) {
                $product['_cost_quantities'][$direction][(string) $date] = $this->sumQuantity(
                    (int) ($product['_cost_quantities'][$direction][(string) $date] ?? 0),
                    (int) $quantity,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $product
     * @param array{listings: array<string, array<string, mixed>>} $context
     *
     * @return array<string, mixed>
     */
    private function finalizeProduct(array $product, array $context): array
    {
        $soldCost = ['minor' => 0, 'covered' => 0, 'missing' => 0, 'fallback' => 0];
        $returnedCost = $soldCost;
        $listingId = is_string($product['listing_id']) ? $product['listing_id'] : null;
        if (null !== $listingId) {
            $costs = $context['listings'][$listingId]['costs'];
            $soldCost = $this->costForBuckets($costs, $product['_cost_quantities']['sale']);
            $returnedCost = $this->costForBuckets($costs, $product['_cost_quantities']['return']);
        } else {
            $soldCost['missing'] = (int) $product['sold_quantity'];
            $returnedCost['missing'] = (int) $product['returned_quantity'];
        }

        $soldQuantity = (int) $product['sold_quantity'];
        $returnedQuantity = (int) $product['returned_quantity'];
        $totalQuantity = $this->sumQuantity($soldQuantity, $returnedQuantity);
        $coveredQuantity = $this->sumQuantity($soldCost['covered'], $returnedCost['covered']);
        $missingQuantity = $this->sumQuantity($soldCost['missing'], $returnedCost['missing']);
        $fallbackQuantity = $this->sumQuantity($soldCost['fallback'], $returnedCost['fallback']);
        $netSalesMinor = $this->subtractMoney(
            (int) $product['sales_without_spp_minor'],
            (int) $product['returns_without_spp_minor'],
        );
        $netSalesWithSppMinor = $this->subtractMoney(
            (int) $product['sales_with_spp_minor'],
            (int) $product['returns_with_spp_minor'],
        );
        $forPayMinor = $this->subtractMoney(
            (int) $product['sales_for_pay_minor'],
            (int) $product['returns_for_pay_minor'],
        );
        $netCostMinor = $this->subtractMoney($soldCost['minor'], $returnedCost['minor']);
        $complete = 0 === $missingQuantity && $totalQuantity > 0;
        $resultMinor = $complete ? $this->subtractMoney($forPayMinor, $netCostMinor) : null;
        $barcodes = array_map('strval', array_keys($product['barcodes']));
        sort($barcodes, \SORT_NATURAL);

        unset($product['_cost_quantities']);

        return [
            ...$product,
            'barcodes' => $barcodes,
            'barcode' => $barcodes[0] ?? '',
            'net_quantity' => $this->subtractQuantity($soldQuantity, $returnedQuantity),
            'net_sales_without_spp_minor' => $netSalesMinor,
            'net_sales_with_spp_minor' => $netSalesWithSppMinor,
            'for_pay_minor' => $forPayMinor,
            'sold_cost_minor' => $soldCost['minor'],
            'returned_cost_minor' => $returnedCost['minor'],
            'net_cost_minor' => $netCostMinor,
            'covered_cost_quantity' => $coveredQuantity,
            'missing_cost_quantity' => $missingQuantity,
            'fallback_cost_quantity' => $fallbackQuantity,
            'cost_coverage_percent' => $this->percent($coveredQuantity, $totalQuantity),
            'cost_status' => $complete ? 'complete' : (0 < $coveredQuantity ? 'partial' : 'missing'),
            'result_minor' => $resultMinor,
            'result_status' => $this->resultStatus($resultMinor),
            'return_rate_percent' => $this->percent($returnedQuantity, $soldQuantity),
            'profitability_percent' => null === $resultMinor
                ? null
                : $this->percent($resultMinor, $netSalesMinor),
        ];
    }

    /**
     * @param list<array<string, mixed>> $costs
     * @param array<string, int> $buckets
     *
     * @return array{minor: int, covered: int, missing: int, fallback: int}
     */
    private function costForBuckets(array $costs, array $buckets): array
    {
        $result = ['minor' => 0, 'covered' => 0, 'missing' => 0, 'fallback' => 0];
        foreach ($buckets as $date => $quantity) {
            [$cost, $fallback] = $this->costAtDate($costs, $date);
            if (null === $cost) {
                $result['missing'] = $this->sumQuantity($result['missing'], $quantity);
                continue;
            }

            $unitCost = $this->costMoney($cost);
            if (null === $unitCost || !$unitCost->isPositive()) {
                $result['missing'] = $this->sumQuantity($result['missing'], $quantity);
                continue;
            }

            $result['minor'] = $this->sumMoney(
                $result['minor'],
                $unitCost->multiply((string) $quantity)->amountMinor(),
            );
            $result['covered'] = $this->sumQuantity($result['covered'], $quantity);
            if ($fallback) {
                $result['fallback'] = $this->sumQuantity($result['fallback'], $quantity);
            }
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $costs
     *
     * @return array{0: array<string, mixed>|null, 1: bool}
     */
    private function costAtDate(array $costs, string $date): array
    {
        $active = null;
        foreach ($costs as $cost) {
            $effectiveFrom = (string) ($cost['effective_from'] ?? '');
            $effectiveTo = $cost['effective_to'] ?? null;
            if ($effectiveFrom <= $date && (null === $effectiveTo || (string) $effectiveTo >= $date)) {
                $active = $cost;
            }
        }

        if (null !== $active) {
            return [$active, false];
        }

        return [] === $costs ? [null, false] : [$costs[0], true];
    }

    /**
     * @param array<string, mixed> $cost
     */
    private function costMoney(array $cost): ?Money
    {
        if (self::CURRENCY !== strtoupper((string) ($cost['price_currency'] ?? ''))) {
            return null;
        }

        return Money::fromString((string) ($cost['price_amount'] ?? ''), self::CURRENCY);
    }

    /**
     * @param list<array<string, mixed>> $products
     *
     * @return array<string, int|string|null>
     */
    private function summary(array $products, int $reportForPayMinor, int $reportNetSalesMinor): array
    {
        $summary = $this->emptySummary($reportForPayMinor, $reportNetSalesMinor);
        $summary['sku_count'] = count($products);
        foreach ($products as $product) {
            foreach ([
                'sold_quantity',
                'returned_quantity',
                'covered_cost_quantity',
                'missing_cost_quantity',
                'fallback_cost_quantity',
            ] as $field) {
                $summary[$field] = $this->sumQuantity((int) $summary[$field], (int) $product[$field]);
            }
            foreach ([
                'sales_without_spp_minor',
                'returns_without_spp_minor',
                'sales_with_spp_minor',
                'returns_with_spp_minor',
                'for_pay_minor',
                'sold_cost_minor',
                'returned_cost_minor',
            ] as $field) {
                $summary[$field] = $this->sumMoney((int) $summary[$field], (int) $product[$field]);
            }

            ++$summary[$product['mapping_status'].'_sku_count'];
            ++$summary[$product['cost_status'].'_cost_sku_count'];
            ++$summary[$product['result_status'].'_result_sku_count'];
            if (null !== $product['result_minor']) {
                $summary['known_result_minor'] = $this->sumMoney(
                    (int) $summary['known_result_minor'],
                    (int) $product['result_minor'],
                );
            }
        }

        $summary['net_quantity'] = $this->subtractQuantity(
            (int) $summary['sold_quantity'],
            (int) $summary['returned_quantity'],
        );
        $summary['net_sales_without_spp_minor'] = $this->subtractMoney(
            (int) $summary['sales_without_spp_minor'],
            (int) $summary['returns_without_spp_minor'],
        );
        $summary['net_sales_with_spp_minor'] = $this->subtractMoney(
            (int) $summary['sales_with_spp_minor'],
            (int) $summary['returns_with_spp_minor'],
        );
        $summary['net_cost_minor'] = $this->subtractMoney(
            (int) $summary['sold_cost_minor'],
            (int) $summary['returned_cost_minor'],
        );
        $totalQuantity = $this->sumQuantity(
            (int) $summary['sold_quantity'],
            (int) $summary['returned_quantity'],
        );
        $summary['cost_coverage_percent'] = $this->percent(
            (int) $summary['covered_cost_quantity'],
            $totalQuantity,
        );
        $summary['unallocated_for_pay_minor'] = $this->subtractMoney(
            $reportForPayMinor,
            (int) $summary['for_pay_minor'],
        );
        $summary['sales_reconciliation_minor'] = $this->subtractMoney(
            $reportNetSalesMinor,
            (int) $summary['net_sales_without_spp_minor'],
        );
        if (0 === (int) $summary['missing_cost_quantity'] && $totalQuantity > 0) {
            $summary['result_minor'] = $this->subtractMoney(
                (int) $summary['for_pay_minor'],
                (int) $summary['net_cost_minor'],
            );
            $summary['profitability_percent'] = $this->percent(
                (int) $summary['result_minor'],
                (int) $summary['net_sales_without_spp_minor'],
            );
        }

        return $summary;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function emptySummary(int $unallocatedForPayMinor, int $salesReconciliationMinor): array
    {
        return [
            'sku_count' => 0,
            'sold_quantity' => 0,
            'returned_quantity' => 0,
            'net_quantity' => 0,
            'sales_without_spp_minor' => 0,
            'returns_without_spp_minor' => 0,
            'net_sales_without_spp_minor' => 0,
            'sales_with_spp_minor' => 0,
            'returns_with_spp_minor' => 0,
            'net_sales_with_spp_minor' => 0,
            'for_pay_minor' => 0,
            'sold_cost_minor' => 0,
            'returned_cost_minor' => 0,
            'net_cost_minor' => 0,
            'covered_cost_quantity' => 0,
            'missing_cost_quantity' => 0,
            'fallback_cost_quantity' => 0,
            'cost_coverage_percent' => null,
            'mapped_sku_count' => 0,
            'unmapped_sku_count' => 0,
            'conflict_sku_count' => 0,
            'complete_cost_sku_count' => 0,
            'partial_cost_sku_count' => 0,
            'missing_cost_sku_count' => 0,
            'positive_result_sku_count' => 0,
            'negative_result_sku_count' => 0,
            'zero_result_sku_count' => 0,
            'unavailable_result_sku_count' => 0,
            'known_result_minor' => 0,
            'result_minor' => null,
            'profitability_percent' => null,
            'unallocated_for_pay_minor' => $unallocatedForPayMinor,
            'sales_reconciliation_minor' => $salesReconciliationMinor,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed>|null $listing
     */
    private function productName(array $source, ?array $listing): string
    {
        $listingName = trim((string) ($listing['name'] ?? ''));
        if ('' !== $listingName) {
            return $listingName;
        }

        $parts = array_values(array_filter([
            trim((string) ($source['brand'] ?? '')),
            trim((string) ($source['subject'] ?? '')),
        ], static fn (string $value): bool => '' !== $value));

        return [] === $parts ? 'Без названия' : implode(' ', $parts);
    }

    private function normalizeWbSize(string $size): string
    {
        $size = trim($size);

        return '' !== $size && '0' !== $size ? $size : 'UNKNOWN';
    }

    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if ('' !== trim($value)) {
                return $value;
            }
        }

        return '';
    }

    private function resultStatus(?int $resultMinor): string
    {
        return match (true) {
            null === $resultMinor => 'unavailable',
            $resultMinor > 0 => 'positive',
            $resultMinor < 0 => 'negative',
            default => 'zero',
        };
    }

    private function percent(int $numerator, int $denominator): ?string
    {
        if ($denominator <= 0) {
            return null;
        }

        return \bcdiv(\bcmul((string) $numerator, '100', 0), (string) $denominator, 1);
    }

    private function sumMoney(int $leftMinor, int $rightMinor): int
    {
        return Money::fromMinor($leftMinor, self::CURRENCY)
            ->add(Money::fromMinor($rightMinor, self::CURRENCY))
            ->amountMinor();
    }

    private function subtractMoney(int $leftMinor, int $rightMinor): int
    {
        return Money::fromMinor($leftMinor, self::CURRENCY)
            ->subtract(Money::fromMinor($rightMinor, self::CURRENCY))
            ->amountMinor();
    }

    private function sumQuantity(int $left, int $right): int
    {
        return $this->quantityFromBc(\bcadd((string) $left, (string) $right, 0));
    }

    private function subtractQuantity(int $left, int $right): int
    {
        return $this->quantityFromBc(\bcsub((string) $left, (string) $right, 0));
    }

    private function quantityFromBc(string $quantity): int
    {
        if (
            \bccomp($quantity, (string) \PHP_INT_MAX, 0) > 0
            || \bccomp($quantity, (string) \PHP_INT_MIN, 0) < 0
        ) {
            throw new \OverflowException('Product quantity exceeds the supported integer range.');
        }

        return (int) $quantity;
    }
}
