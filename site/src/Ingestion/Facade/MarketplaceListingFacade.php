<?php

declare(strict_types=1);

namespace App\Ingestion\Facade;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;

final readonly class MarketplaceListingFacade
{
    public function __construct(
        private MarketplaceListingRepository $listingRepository,
        private MarketplaceListingBarcodeRepository $barcodeRepository,
    ) {
    }

    public function findBySupplierSku(string $companyId, string $marketplace, string $supplierSku): ?string
    {
        $marketplaceType = MarketplaceType::tryFrom($marketplace);
        if (null === $marketplaceType) {
            return null;
        }

        return $this->listingRepository
            ->findBySupplierSku($companyId, $marketplaceType, $supplierSku)
            ?->getId();
    }

    public function findByMarketplaceSku(string $companyId, string $marketplace, string $marketplaceSku): ?string
    {
        $marketplaceType = MarketplaceType::tryFrom($marketplace);
        if (null === $marketplaceType) {
            return null;
        }

        return $this->listingRepository
            ->findByMarketplaceSku($companyId, $marketplaceType, $marketplaceSku)
            ?->getId();
    }

    public function findByBarcode(string $companyId, string $marketplace, string $barcode): ?string
    {
        $marketplaceType = MarketplaceType::tryFrom($marketplace);
        if (null === $marketplaceType) {
            return null;
        }

        return $this->barcodeRepository->findListingIdByBarcode($companyId, $barcode, $marketplaceType);
    }
}
