<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\ListingResolution;
use App\Ingestion\Domain\Contract\ListingResolverInterface;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\MarketplaceListingFacade;
use App\Marketplace\Enum\MarketplaceType;

final class OzonListingResolver implements ListingResolverInterface
{
    public function __construct(private readonly MarketplaceListingFacade $marketplaceListingFacade)
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
        $supplierResolution = null;
        $supplierSku = $this->stringValue($sourceData['offer_id'] ?? $sourceData['item_code'] ?? null);
        if (null !== $supplierSku) {
            $supplierResolution = new ListingResolution(
                $this->marketplaceListingFacade->findBySupplierSku($companyId, MarketplaceType::OZON->value, $supplierSku),
                $supplierSku,
            );

            if (null !== $supplierResolution->listingId) {
                return $supplierResolution;
            }
        }

        $marketplaceSku = $this->marketplaceSku($sourceData);
        if (null !== $marketplaceSku) {
            $marketplaceResolution = new ListingResolution(
                $this->marketplaceListingFacade->findByMarketplaceSku($companyId, MarketplaceType::OZON->value, $marketplaceSku),
                $marketplaceSku,
            );

            if (null !== $marketplaceResolution->listingId || null === $supplierResolution) {
                return $marketplaceResolution;
            }
        }

        return $supplierResolution;
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

    private function stringValue(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $stringValue = trim((string) $value);

        return '' === $stringValue ? null : $stringValue;
    }
}
