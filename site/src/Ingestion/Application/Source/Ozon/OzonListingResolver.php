<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\ListingResolution;
use App\Ingestion\Domain\Contract\BulkListingResolverInterface;
use App\Ingestion\Enum\IngestSource;
use App\Marketplace\DTO\MarketplaceListingSeedDTO;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceListingLinkingFacade;

final class OzonListingResolver implements BulkListingResolverInterface
{
    public function __construct(private readonly MarketplaceListingLinkingFacade $marketplaceListingFacade)
    {
    }

    public function supports(IngestSource $source): bool
    {
        return IngestSource::OZON === $source;
    }

    /**
     * @param array<string, mixed> $sourceData
     */
    public function resolve(string $companyId, array $sourceData): ?ListingResolution
    {
        return $this->resolveMany($companyId, [0 => $sourceData])[0] ?? null;
    }

    /**
     * @param array<int|string, array<string, mixed>> $sourceDataRows
     *
     * @return array<int|string, ListingResolution|null>
     */
    public function resolveMany(string $companyId, array $sourceDataRows): array
    {
        return $this->resolveManyInternal($companyId, $sourceDataRows, createMissing: true);
    }

    /**
     * @param array<int|string, array<string, mixed>> $sourceDataRows
     *
     * @return array<int|string, ListingResolution|null>
     */
    public function resolveManyReadOnly(string $companyId, array $sourceDataRows): array
    {
        return $this->resolveManyInternal($companyId, $sourceDataRows, createMissing: false);
    }

    /**
     * @param array<int|string, array<string, mixed>> $sourceDataRows
     *
     * @return array<int|string, ListingResolution|null>
     */
    private function resolveManyInternal(string $companyId, array $sourceDataRows, bool $createMissing): array
    {
        $result = [];
        $seedsByKey = [];

        foreach ($sourceDataRows as $key => $sourceData) {
            $result[$key] = null;

            $supplierResolution = null;
            $supplierSku = $this->stringValue($sourceData['offer_id'] ?? $sourceData['item_code'] ?? null);
            if (null !== $supplierSku) {
                $supplierReference = $this->marketplaceListingFacade->findBySupplierSku($companyId, MarketplaceType::OZON->value, $supplierSku);
                $supplierResolution = new ListingResolution($supplierReference?->listingId, $supplierSku);

                if (null !== $supplierResolution->listingId) {
                    $result[$key] = $supplierResolution;
                    continue;
                }
            }

            $marketplaceSku = $this->marketplaceSku($sourceData);
            if (null === $marketplaceSku) {
                $result[$key] = $supplierResolution;
                continue;
            }

            $seedsByKey[$key] = new MarketplaceListingSeedDTO(
                marketplaceSku: $marketplaceSku,
                supplierSku: $supplierSku,
                name: $this->listingName($sourceData),
            );
            $result[$key] = $supplierResolution ?? new ListingResolution(null, $marketplaceSku);
        }

        if ([] === $seedsByKey) {
            return $result;
        }

        $references = $createMissing
            ? $this->marketplaceListingFacade->ensureOzonListings($companyId, array_values($seedsByKey))
            : $this->findExistingMarketplaceReferences($companyId, array_values($seedsByKey));

        foreach ($seedsByKey as $key => $seed) {
            $reference = $references[$seed->marketplaceSku] ?? null;
            if (null === $reference) {
                if ($result[$key] instanceof ListingResolution && null !== $result[$key]?->listingSku) {
                    continue;
                }

                $result[$key] = new ListingResolution(null, $seed->marketplaceSku);
                continue;
            }

            $result[$key] = new ListingResolution($reference->listingId, $reference->marketplaceSku);
        }

        return $result;
    }

    /**
     * @param list<MarketplaceListingSeedDTO> $seeds
     *
     * @return array<string, \App\Marketplace\DTO\MarketplaceListingReferenceDTO>
     */
    private function findExistingMarketplaceReferences(string $companyId, array $seeds): array
    {
        $references = [];
        foreach ($seeds as $seed) {
            $references[$seed->marketplaceSku] ??= $this->marketplaceListingFacade->findByMarketplaceSku(
                $companyId,
                MarketplaceType::OZON->value,
                $seed->marketplaceSku,
            );
        }

        return array_filter($references);
    }

    /**
     * @param array<string, mixed> $sourceData
     */
    private function marketplaceSku(array $sourceData): ?string
    {
        $direct = $this->stringValue($sourceData['sku'] ?? null);
        if (null !== $direct) {
            return $direct;
        }

        $item = $sourceData['item'] ?? null;
        if (is_array($item)) {
            $itemSku = $this->stringValue($item['sku'] ?? null);
            if (null !== $itemSku) {
                return $itemSku;
            }
        }

        $items = $sourceData['items'] ?? null;
        if (is_array($items) && isset($items[0]) && is_array($items[0])) {
            return $this->stringValue($items[0]['sku'] ?? null);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $sourceData
     */
    private function listingName(array $sourceData): ?string
    {
        $direct = $this->stringValue($sourceData['name'] ?? null);
        if (null !== $direct) {
            return $direct;
        }

        $item = $sourceData['item'] ?? null;
        if (is_array($item)) {
            $itemName = $this->stringValue($item['name'] ?? null);
            if (null !== $itemName) {
                return $itemName;
            }
        }

        $items = $sourceData['items'] ?? null;
        if (is_array($items) && isset($items[0]) && is_array($items[0])) {
            return $this->stringValue($items[0]['name'] ?? null);
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $stringValue = trim((string) $value);

        return '' === $stringValue ? null : $stringValue;
    }
}
