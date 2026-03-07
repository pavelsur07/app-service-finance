<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class WbListingResolverService
{
    public function __construct(
        private readonly MarketplaceListingRepository $listingRepository,
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
    ): MarketplaceListing {
        $size = $this->normalizeWbSize($tsName);

        $listing = $this->listingRepository->findByNmIdAndSize(
            $company,
            MarketplaceType::WILDBERRIES,
            $nmId,
            $size,
        );

        if ($listing !== null) {
            return $listing;
        }

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

        return $listing;
    }

    private function normalizeWbSize(?string $tsName): string
    {
        $normalized = trim((string) $tsName);

        return $normalized !== '' ? $normalized : 'UNKNOWN';
    }
}
