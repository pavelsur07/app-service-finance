<?php

declare(strict_types=1);

namespace App\Inventory\Application;

use App\Inventory\Application\DTO\NormalizedStockRow;
use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Entity\Location;
use App\Inventory\Entity\StockSnapshot;
use App\Inventory\Enum\LocationType;
use App\Inventory\Enum\SnapshotSessionStatus;
use App\Inventory\Enum\StockSnapshotMappingStatus;
use App\Inventory\Enum\StockStatus;
use App\Inventory\Infrastructure\Normalizer\OzonProductStocksRawNormalizer;
use App\Inventory\Infrastructure\Normalizer\WbWarehouseStocksRawNormalizer;
use App\Inventory\Repository\InventoryRawSnapshotRepository;
use App\Inventory\Repository\InventorySnapshotSessionRepository;
use App\Inventory\Repository\LocationRepository;
use App\Inventory\Repository\StockSnapshotRepository;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;

final readonly class NormalizeInventorySnapshotAction
{
    public function __construct(
        private InventorySnapshotSessionRepository $sessionRepository,
        private InventoryRawSnapshotRepository $rawSnapshotRepository,
        private OzonProductStocksRawNormalizer $ozonRawNormalizer,
        private WbWarehouseStocksRawNormalizer $wbRawNormalizer,
        private MarketplaceFacade $marketplaceFacade,
        private LocationRepository $locationRepository,
        private StockSnapshotRepository $stockSnapshotRepository,
        private EntityManagerInterface $entityManager,
        private AppLogger $logger,
    ) {
    }

    public function __invoke(string $companyId, string $snapshotSessionId, MarketplaceType $source): void
    {
        $session = $this->sessionRepository->findByIdAndCompany($snapshotSessionId, $companyId);
        if (null === $session) {
            $this->logger->warning('Normalization skipped: session not found.', compact('companyId', 'snapshotSessionId'));

            return;
        }

        if (SnapshotSessionStatus::Completed !== $session->getStatus()) {
            $this->logger->warning('Normalization skipped: session is not completed.', [
                'companyId' => $companyId,
                'snapshotSessionId' => $snapshotSessionId,
                'status' => $session->getStatus()->value,
            ]);

            return;
        }

        if (!in_array($source, [MarketplaceType::OZON, MarketplaceType::WILDBERRIES], true)) {
            $this->logger->warning('Normalization skipped: source is not supported.', ['source' => $source->value]);

            return;
        }

        $rawSnapshots = $this->rawSnapshotRepository->findBySessionAndCompanyOrdered($snapshotSessionId, $companyId);
        if ([] === $rawSnapshots) {
            return;
        }

        if (MarketplaceType::WILDBERRIES === $source) {
            $this->normalizeWildberries($companyId, $snapshotSessionId, $session, $rawSnapshots);

            return;
        }

        $rowsByRaw = [];
        $sourceSkus = [];
        foreach ($rawSnapshots as $rawSnapshot) {
            $rows = $this->ozonRawNormalizer->normalize($rawSnapshot);
            $rowsByRaw[$rawSnapshot->getId()] = $rows;
            foreach ($rows as $row) {
                $sourceSkus[] = $row->sourceSku;
            }
        }

        if ([] === $sourceSkus) {
            foreach ($rawSnapshots as $rawSnapshot) {
                $rawSnapshot->markAsProcessed();
            }

            $this->entityManager->flush();

            return;
        }

        $listingsBySku = $this->marketplaceFacade->findListingsByMarketplaceSkus($companyId, MarketplaceType::OZON->value, array_values(array_unique($sourceSkus)));

        $mappedListingIds = [];
        $mappingBySku = [];
        foreach (array_unique($sourceSkus) as $sku) {
            $matches = $listingsBySku[$sku] ?? [];
            if (1 === count($matches)) {
                $mappingBySku[$sku] = ['status' => StockSnapshotMappingStatus::Mapped, 'listingId' => $matches[0]['id']];
                $mappedListingIds[] = $matches[0]['id'];
                continue;
            }

            $mappingBySku[$sku] = count($matches) > 1
                ? ['status' => StockSnapshotMappingStatus::Ambiguous, 'listingId' => null]
                : ['status' => StockSnapshotMappingStatus::Unmapped, 'listingId' => null];
        }

        $productsByListing = $this->marketplaceFacade->resolveListingsToProducts($companyId, array_values(array_unique($mappedListingIds)));
        $locationByFulfillmentType = $this->resolveLocationsByFulfillmentType($companyId, $source, $rowsByRaw);

        foreach ($rawSnapshots as $rawSnapshot) {
            foreach ($rowsByRaw[$rawSnapshot->getId()] ?? [] as $row) {
                $mapping = $mappingBySku[$row->sourceSku] ?? ['status' => StockSnapshotMappingStatus::Unmapped, 'listingId' => null];
                $listingId = $mapping['listingId'];
                $productId = null !== $listingId ? ($productsByListing[$listingId] ?? null) : null;
                $location = $locationByFulfillmentType[$this->normalizeFulfillmentType($row->fulfillmentType)];

                $this->stockSnapshotRepository->upsertDaySnapshot(new StockSnapshot(
                    companyId: $companyId,
                    snapshotSessionId: $snapshotSessionId,
                    snapshotDate: $rawSnapshot->getFetchedAt(),
                    snapshotAt: $rawSnapshot->getFetchedAt(),
                    locationId: $location->getId(),
                    status: StockStatus::Available,
                    quantity: $row->quantity,
                    reservedQuantity: $row->reservedQuantity,
                    source: MarketplaceType::OZON,
                    rawSnapshotId: $rawSnapshot->getId(),
                    listingId: $listingId,
                    productId: $productId,
                    sourceSku: $row->sourceSku,
                    sourceOfferId: $row->sourceOfferId,
                    fulfillmentType: $row->fulfillmentType,
                    mappingStatus: $mapping['status'],
                ));
            }

            $rawSnapshot->markAsProcessed();
        }

        $this->entityManager->flush();
    }

    /**
     * @param list<InventoryRawSnapshot> $rawSnapshots
     */
    private function normalizeWildberries(string $companyId, string $snapshotSessionId, InventorySnapshotSession $session, array $rawSnapshots): void
    {
        $rows = $this->wbRawNormalizer->normalize($rawSnapshots);
        if ([] === $rows) {
            foreach ($rawSnapshots as $rawSnapshot) {
                $rawSnapshot->markAsProcessed();
            }
            $this->entityManager->flush();

            return;
        }

        $marketplaceVariantIds = array_values(array_unique(array_map(
            static fn (NormalizedStockRow $row): string => $row->sourceSku,
            $rows,
        )));
        $listingsByVariantId = [];
        foreach (array_chunk($marketplaceVariantIds, 5000) as $marketplaceVariantIdChunk) {
            $listingsByVariantId = array_replace(
                $listingsByVariantId,
                $this->marketplaceFacade->findListingsByMarketplaceVariantIds(
                    $companyId,
                    MarketplaceType::WILDBERRIES->value,
                    $marketplaceVariantIdChunk,
                ),
            );
        }

        $mappedListingIds = [];
        $mappingByVariantId = [];
        foreach ($marketplaceVariantIds as $marketplaceVariantId) {
            $listing = $listingsByVariantId[$marketplaceVariantId] ?? null;
            $mappingByVariantId[$marketplaceVariantId] = null === $listing
                ? ['status' => StockSnapshotMappingStatus::Unmapped, 'listingId' => null]
                : ['status' => StockSnapshotMappingStatus::Mapped, 'listingId' => $listing['id']];
            if (null !== $listing) {
                $mappedListingIds[] = $listing['id'];
            }
        }

        $productsByListing = [];
        foreach (array_chunk(array_values(array_unique($mappedListingIds)), 5000) as $listingIdChunk) {
            $productsByListing = array_replace(
                $productsByListing,
                $this->marketplaceFacade->resolveListingsToProducts($companyId, $listingIdChunk),
            );
        }

        $locationExternalIds = array_values(array_unique(array_map(
            static fn (NormalizedStockRow $row): string => (string) $row->locationExternalId,
            $rows,
        )));
        $locations = $this->locationRepository->findByCompanySourceAndExternalIds(
            $companyId,
            MarketplaceType::WILDBERRIES,
            $locationExternalIds,
        );

        foreach ($rows as $row) {
            $locationExternalId = (string) $row->locationExternalId;
            if (!isset($locations[$locationExternalId])) {
                $locations[$locationExternalId] = $this->findOrCreateWbLocation($companyId, $row);
            } else {
                $locations[$locationExternalId]
                    ->setCode((string) $row->locationCode)
                    ->setName((string) $row->locationName)
                    ->setMetadata($row->locationMetadata)
                    ->setIsActive(true);
            }

            $mapping = $mappingByVariantId[$row->sourceSku] ?? ['status' => StockSnapshotMappingStatus::Unmapped, 'listingId' => null];
            $listingId = $mapping['listingId'];
            $productId = null !== $listingId ? ($productsByListing[$listingId] ?? null) : null;

            $this->stockSnapshotRepository->upsertDaySnapshot(new StockSnapshot(
                companyId: $companyId,
                snapshotSessionId: $snapshotSessionId,
                snapshotDate: $session->getStartedAt(),
                snapshotAt: $session->getStartedAt(),
                locationId: $locations[$locationExternalId]->getId(),
                status: $row->status,
                quantity: $row->quantity,
                reservedQuantity: $row->reservedQuantity,
                source: MarketplaceType::WILDBERRIES,
                rawSnapshotId: $row->rawSnapshotId,
                listingId: $listingId,
                productId: $productId,
                sourceSku: $row->sourceSku,
                sourceOfferId: $row->sourceOfferId,
                fulfillmentType: $row->fulfillmentType,
                mappingStatus: $mapping['status'],
            ));
        }

        foreach ($rawSnapshots as $rawSnapshot) {
            $rawSnapshot->markAsProcessed();
        }
        $this->entityManager->flush();
    }

    private function findOrCreateWbLocation(string $companyId, NormalizedStockRow $row): Location
    {
        $externalId = (string) $row->locationExternalId;
        $location = new Location(
            companyId: $companyId,
            type: LocationType::MpWarehouse,
            externalSystem: MarketplaceType::WILDBERRIES,
            code: (string) $row->locationCode,
            name: (string) $row->locationName,
            externalId: $externalId,
            metadata: $row->locationMetadata,
        );
        $this->entityManager->persist($location);

        return $location;
    }

    /**
     * @param array<string, list<NormalizedStockRow>> $rowsByRaw
     *
     * @return array<string, Location>
     */
    private function resolveLocationsByFulfillmentType(string $companyId, MarketplaceType $source, array $rowsByRaw): array
    {
        $locations = [];
        foreach ($rowsByRaw as $rows) {
            foreach ($rows as $row) {
                $normalizedFulfillmentType = $this->normalizeFulfillmentType($row->fulfillmentType);
                if (isset($locations[$normalizedFulfillmentType])) {
                    continue;
                }

                $locations[$normalizedFulfillmentType] = $this->findOrCreateLocation($companyId, $source, $row->fulfillmentType);
            }
        }

        return $locations;
    }

    private function findOrCreateLocation(string $companyId, MarketplaceType $source, ?string $fulfillmentType): Location
    {
        $externalId = $this->normalizeFulfillmentType($fulfillmentType);
        $location = $this->locationRepository->findOneBy([
            'companyId' => $companyId,
            'externalSystem' => $source,
            'externalId' => $externalId,
        ]);

        if (null !== $location) {
            return $location;
        }

        $location = new Location(
            companyId: $companyId,
            type: LocationType::MpWarehouse,
            externalSystem: $source,
            code: strtoupper($externalId),
            name: sprintf('Ozon %s', strtoupper($externalId)),
            externalId: $externalId,
        );
        $this->entityManager->persist($location);

        return $location;
    }

    private function normalizeFulfillmentType(?string $fulfillmentType): string
    {
        $normalizedValue = trim((string) $fulfillmentType);

        return '' !== $normalizedValue ? mb_strtolower($normalizedValue) : 'unknown';
    }
}
