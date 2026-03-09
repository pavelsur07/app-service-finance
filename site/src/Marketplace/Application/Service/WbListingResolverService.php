<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceListingBarcode;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class WbListingResolverService
{
    public function __construct(
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly MarketplaceListingBarcodeRepository $barcodeRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Найти существующий листинг WB или создать новый.
     * Единственное место создания MarketplaceListing для Wildberries.
     * flush() НЕ вызывается — ответственность вызывающего кода.
     *
     * @param array<string, mixed> $wbMeta sa_name, brand_name, subject_name, retail_price
     */
    public function resolve(
        Company $company,
        string $nmId,
        ?string $tsName,
        array $wbMeta = [],
        ?string $barcode = null,
    ): MarketplaceListing {
        $companyId = (string) $company->getId();
        $size = $this->normalizeWbSize($tsName);

        // 1. Ищем по nmId + size
        $listing = $this->listingRepository->findByNmIdAndSize(
            $company,
            MarketplaceType::WILDBERRIES,
            $nmId,
            $size,
        );

        if ($listing !== null) {
            return $listing;
        }

        // 2. Если size=UNKNOWN и есть barcode — ищем листинг через barcode + marketplace
        if ($size === 'UNKNOWN' && $barcode !== null && $barcode !== '') {
            $barcodeEntity = $this->barcodeRepository->findByBarcode(
                $companyId,
                $barcode,
                MarketplaceType::WILDBERRIES,
            );
            if ($barcodeEntity !== null) {
                return $barcodeEntity->getListing();
            }
        }

        // 3. Создаём новый листинг
        $saName = (string) ($wbMeta['sa_name'] ?? '');
        $brandName = (string) ($wbMeta['brand_name'] ?? '');
        $subjectName = (string) ($wbMeta['subject_name'] ?? '');
        $price = (string) ($wbMeta['retail_price'] ?? '0');

        $nameParts = array_filter([
            $brandName,
            $subjectName,
            $saName,
            $size !== 'UNKNOWN' ? $size : null,
        ]);

        $listing = new MarketplaceListing(
            Uuid::uuid4()->toString(),
            $company,
            null,
            MarketplaceType::WILDBERRIES,
        );

        $listing->setMarketplaceSku($nmId);
        $listing->setSize($size);
        $listing->setSupplierSku($saName !== '' ? $saName : null);
        $listing->setPrice($price !== '' ? $price : '0');
        $listing->setName($nameParts !== [] ? implode(' ', $nameParts) : null);

        $this->em->persist($listing);

        // Сохраняем barcode только при создании нового листинга с известным размером
        if ($barcode !== null && $barcode !== '' && $size !== 'UNKNOWN') {
            $barcodeEntity = new MarketplaceListingBarcode(
                Uuid::uuid4()->toString(),
                $listing,
                $companyId,
                $barcode,
            );
            $this->em->persist($barcodeEntity);
        }

        return $listing;
    }

    private function normalizeWbSize(?string $tsName): string
    {
        $normalized = trim((string) $tsName);

        return $normalized !== '' ? $normalized : 'UNKNOWN';
    }
}
