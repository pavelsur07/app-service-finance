<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\OzonListingEnsureService;
use App\Marketplace\DTO\MarketplaceListingReferenceDTO;
use App\Marketplace\DTO\MarketplaceListingSeedDTO;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MarketplaceListingLinkingFacade
{
    public function __construct(
        private MarketplaceListingRepository $listingRepository,
        private OzonListingEnsureService $ozonListingEnsureService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findBySupplierSku(string $companyId, string $marketplace, string $supplierSku): ?MarketplaceListingReferenceDTO
    {
        $marketplaceType = MarketplaceType::tryFrom($marketplace);
        if (null === $marketplaceType) {
            return null;
        }

        $listing = $this->listingRepository->findBySupplierSku($companyId, $marketplaceType, $supplierSku);

        return null === $listing ? null : $this->reference($listing);
    }

    /**
     * @param list<string> $supplierSkus
     *
     * @return array<string, MarketplaceListingReferenceDTO>
     */
    public function findBySupplierSkus(string $companyId, string $marketplace, array $supplierSkus): array
    {
        $marketplaceType = MarketplaceType::tryFrom($marketplace);
        if (null === $marketplaceType) {
            return [];
        }

        $supplierSkus = $this->normalizeStringList($supplierSkus);
        if ([] === $supplierSkus) {
            return [];
        }

        return $this->uniqueReferencesFromGroups($this->groupListingsByKey(
            $this->listingRepository->findAllByCompanyMarketplaceAndSupplierSkus($companyId, $marketplaceType, $supplierSkus),
            static fn (MarketplaceListing $listing): ?string => $listing->getSupplierSku(),
        ));
    }

    public function findByMarketplaceSku(string $companyId, string $marketplace, string $marketplaceSku): ?MarketplaceListingReferenceDTO
    {
        $marketplaceType = MarketplaceType::tryFrom($marketplace);
        if (null === $marketplaceType) {
            return null;
        }

        $listing = $this->listingRepository->findByMarketplaceSku($companyId, $marketplaceType, $marketplaceSku);

        return null === $listing ? null : $this->reference($listing);
    }

    /**
     * @param list<string> $marketplaceSkus
     *
     * @return array<string, MarketplaceListingReferenceDTO>
     */
    public function findByMarketplaceSkus(string $companyId, string $marketplace, array $marketplaceSkus): array
    {
        $marketplaceType = MarketplaceType::tryFrom($marketplace);
        if (null === $marketplaceType) {
            return [];
        }

        $marketplaceSkus = $this->normalizeStringList($marketplaceSkus);
        if ([] === $marketplaceSkus) {
            return [];
        }

        return $this->uniqueReferencesFromGroups($this->groupListingsByKey(
            $this->listingRepository->findAllByCompanyMarketplaceAndMarketplaceSkus($companyId, $marketplaceType, $marketplaceSkus),
            static fn (MarketplaceListing $listing): string => $listing->getMarketplaceSku(),
        ));
    }

    /**
     * @param iterable<MarketplaceListingSeedDTO> $seeds
     *
     * @return array<string, MarketplaceListingReferenceDTO>
     */
    public function ensureOzonListings(string $companyId, iterable $seeds): array
    {
        $company = $this->entityManager->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company was not found for Ozon listing ensure.');
        }

        $metadataChanged = false;
        $uniqueSeeds = [];
        foreach ($seeds as $seed) {
            if (!isset($uniqueSeeds[$seed->marketplaceSku])) {
                $uniqueSeeds[$seed->marketplaceSku] = $seed;
                continue;
            }

            $existing = $uniqueSeeds[$seed->marketplaceSku];
            if ((null === $existing->supplierSku && null !== $seed->supplierSku) || (null === $existing->name && null !== $seed->name)) {
                $uniqueSeeds[$seed->marketplaceSku] = new MarketplaceListingSeedDTO(
                    marketplaceSku: $existing->marketplaceSku,
                    supplierSku: $existing->supplierSku ?? $seed->supplierSku,
                    name: $existing->name ?? $seed->name,
                );
            }
        }

        if ([] === $uniqueSeeds) {
            return [];
        }

        $result = [];
        $missingSkusWithNames = [];
        $supplierSkusBySku = [];
        $existingListingsBySku = $this->groupListingsByKey(
            $this->listingRepository->findAllByCompanyMarketplaceAndMarketplaceSkus(
                $companyId,
                MarketplaceType::OZON,
                array_keys($uniqueSeeds),
            ),
            static fn (MarketplaceListing $listing): string => $listing->getMarketplaceSku(),
        );

        foreach ($uniqueSeeds as $sku => $seed) {
            $matches = $existingListingsBySku[$sku] ?? [];
            if (1 === count($matches)) {
                $listing = $matches[0];
                $metadataChanged = $this->fillMissingMetadata($listing, $seed) || $metadataChanged;
                $result[$sku] = $this->reference($listing);
                continue;
            }

            if (count($matches) > 1) {
                continue;
            }

            $missingSkusWithNames[$sku] = $seed->name;
            if (null !== $seed->supplierSku) {
                $supplierSkusBySku[$sku] = $seed->supplierSku;
            }
        }

        if ($metadataChanged) {
            $this->entityManager->flush();
        }

        foreach ($this->ozonListingEnsureService->ensureListings($company, $missingSkusWithNames, $supplierSkusBySku) as $sku => $listing) {
            $result[$sku] = $this->reference($listing);
        }

        return $result;
    }

    private function reference(MarketplaceListing $listing): MarketplaceListingReferenceDTO
    {
        return new MarketplaceListingReferenceDTO(
            listingId: $listing->getId(),
            marketplaceSku: $listing->getMarketplaceSku(),
            supplierSku: $listing->getSupplierSku(),
        );
    }

    private function fillMissingMetadata(MarketplaceListing $listing, MarketplaceListingSeedDTO $seed): bool
    {
        $changed = false;

        if (null === $listing->getSupplierSku() && null !== $seed->supplierSku) {
            $listing->setSupplierSku($seed->supplierSku);
            $changed = true;
        }

        if ((null === $listing->getName() || '' === trim($listing->getName())) && null !== $seed->name) {
            $listing->setName($seed->name);
            $changed = true;
        }

        return $changed;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ('' === $value) {
                continue;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    /**
     * @param list<MarketplaceListing> $listings
     * @param callable(MarketplaceListing): ?string $keyExtractor
     *
     * @return array<string, list<MarketplaceListing>>
     */
    private function groupListingsByKey(array $listings, callable $keyExtractor): array
    {
        $grouped = [];
        foreach ($listings as $listing) {
            $key = $keyExtractor($listing);
            if (null === $key || '' === trim($key)) {
                continue;
            }

            $grouped[$key][] = $listing;
        }

        return $grouped;
    }

    /**
     * @param array<string, list<MarketplaceListing>> $groups
     *
     * @return array<string, MarketplaceListingReferenceDTO>
     */
    private function uniqueReferencesFromGroups(array $groups): array
    {
        $references = [];
        foreach ($groups as $key => $matches) {
            if (1 === count($matches)) {
                $references[$key] = $this->reference($matches[0]);
            }
        }

        return $references;
    }
}
