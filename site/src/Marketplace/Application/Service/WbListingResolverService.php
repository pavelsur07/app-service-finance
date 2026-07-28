<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\WbBarcodeUpsertQuery;
use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class WbListingResolverService
{
    /** @var list<array{companyId: string, listingId: string, barcode: string}> */
    private array $pendingBarcodes = [];

    public function __construct(
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly MarketplaceListingBarcodeRepository $barcodeRepository,
        private readonly WbBarcodeUpsertQuery $barcodeUpsertQuery,
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
        ?string $marketplaceVariantId = null,
    ): MarketplaceListing {
        $companyId = (string) $company->getId();
        $size = $this->normalizeWbSize($tsName);
        $marketplaceVariantId = null === $marketplaceVariantId ? null : trim($marketplaceVariantId);
        $marketplaceVariantId = '' === $marketplaceVariantId ? null : $marketplaceVariantId;

        $listingByNaturalKey = $this->listingRepository->findByNmIdAndSize(
            $company,
            MarketplaceType::WILDBERRIES,
            $nmId,
            $size,
        );
        $listingByVariant = null === $marketplaceVariantId
            ? null
            : $this->listingRepository->findByMarketplaceVariantId($company, MarketplaceType::WILDBERRIES, $marketplaceVariantId);

        if (null !== $listingByNaturalKey || null !== $listingByVariant) {
            return $this->resolveKnownCandidates(
                $company,
                $nmId,
                $size,
                $marketplaceVariantId,
                $wbMeta,
                $barcode,
                $listingByNaturalKey,
                $listingByVariant,
            );
        }

        if ($size === 'UNKNOWN' && $barcode !== null && $barcode !== '') {
            $barcodeEntity = $this->barcodeRepository->findByBarcode(
                $companyId,
                $barcode,
                MarketplaceType::WILDBERRIES,
            );
            if ($barcodeEntity !== null) {
                $listing = $barcodeEntity->getListing();
                $this->bindVariantIdentity($listing, $nmId, $size, $marketplaceVariantId);

                return $listing;
            }
        }

        return $this->createListing($company, $nmId, $size, $marketplaceVariantId, $wbMeta, $barcode);
    }

    /**
     * Resolver entry point for catalog sync with bulk-preloaded candidates.
     *
     * @param array<string, mixed> $wbMeta
     */
    public function resolveCatalogVariant(
        Company $company,
        string $nmId,
        string $size,
        string $marketplaceVariantId,
        ?MarketplaceListing $listingByNaturalKey,
        ?MarketplaceListing $listingByVariant,
        array $wbMeta = [],
    ): MarketplaceListing {
        return $this->resolveKnownCandidates(
            $company,
            $nmId,
            $this->normalizeWbSize($size),
            trim($marketplaceVariantId),
            $wbMeta,
            null,
            $listingByNaturalKey,
            $listingByVariant,
        );
    }

    /** @param array<string, mixed> $wbMeta */
    private function resolveKnownCandidates(
        Company $company,
        string $nmId,
        string $size,
        ?string $marketplaceVariantId,
        array $wbMeta,
        ?string $barcode,
        ?MarketplaceListing $listingByNaturalKey,
        ?MarketplaceListing $listingByVariant,
    ): MarketplaceListing {
        if (null !== $listingByNaturalKey && null !== $listingByVariant && $listingByNaturalKey->getId() !== $listingByVariant->getId()) {
            throw new \DomainException(sprintf('WB listing identity conflict for nmId=%s, size=%s, chrtId=%s.', $nmId, $size, $marketplaceVariantId));
        }

        $listing = $listingByNaturalKey ?? $listingByVariant;
        if (null !== $listing) {
            $this->bindVariantIdentity($listing, $nmId, $size, $marketplaceVariantId);

            return $listing;
        }

        return $this->createListing($company, $nmId, $size, $marketplaceVariantId, $wbMeta, $barcode);
    }

    /** @param array<string, mixed> $wbMeta */
    private function createListing(
        Company $company,
        string $nmId,
        string $size,
        ?string $marketplaceVariantId,
        array $wbMeta,
        ?string $barcode,
    ): MarketplaceListing {
        $companyId = (string) $company->getId();
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
        $listing->setMarketplaceVariantId($marketplaceVariantId);
        $listing->setSize($size);
        $listing->setSupplierSku($saName !== '' ? $saName : null);
        $listing->setPrice($price !== '' ? $price : '0');
        $listing->setName($nameParts !== [] ? implode(' ', $nameParts) : null);

        $this->em->persist($listing);

        // Откладываем вставку баркода до вызова flushBarcodes() после em->flush(),
        // чтобы FK (listing_id → marketplace_listings) был уже в БД.
        // Безразмерные листинги тоже получают баркод: сюда попадаем только когда
        // resolve() не нашёл листинг ни по натуральному ключу, ни по chrtId, ни по
        // самому баркоду — значит баркод свободен. Это же даёт следующим безразмерным
        // строкам найти листинг по баркоду вместо создания дубля.
        if ($barcode !== null && $barcode !== '') {
            $this->pendingBarcodes[] = [
                'companyId' => $companyId,
                'listingId' => $listing->getId(),
                'barcode'   => $barcode,
            ];
        }

        return $listing;
    }

    /**
     * Записывает накопленные баркоды через идемпотентный INSERT ON CONFLICT DO NOTHING.
     * Вызывать ПОСЛЕ em->flush() — листинги должны уже быть в БД.
     */
    public function flushBarcodes(): void
    {
        foreach ($this->pendingBarcodes as $pending) {
            $this->barcodeUpsertQuery->upsertIfNotExists(
                $pending['companyId'],
                $pending['listingId'],
                $pending['barcode'],
            );
        }
        $this->pendingBarcodes = [];
    }

    /**
     * WB отдаёт безразмерный товар как ts_name="0" в отчётах и как ""
     * в карточках каталога. Без общей нормализации на один nm_id
     * заводится два листинга: size='0' и size='UNKNOWN'.
     */
    private function normalizeWbSize(?string $tsName): string
    {
        $normalized = trim((string) $tsName);

        return $normalized !== '' && $normalized !== '0' ? $normalized : 'UNKNOWN';
    }

    private function bindVariantIdentity(
        MarketplaceListing $listing,
        string $nmId,
        string $size,
        ?string $marketplaceVariantId,
    ): void {
        if (null === $marketplaceVariantId) {
            return;
        }

        if ($listing->getMarketplaceSku() !== $nmId || $listing->getSize() !== $size) {
            throw new \DomainException(sprintf('WB chrtId=%s belongs to another listing.', $marketplaceVariantId));
        }

        $existingVariantId = $listing->getMarketplaceVariantId();
        if (null !== $existingVariantId && $existingVariantId !== $marketplaceVariantId) {
            throw new \DomainException(sprintf('WB listing already has chrtId=%s, cannot assign chrtId=%s.', $existingVariantId, $marketplaceVariantId));
        }

        $listing->setMarketplaceVariantId($marketplaceVariantId);
    }
}
