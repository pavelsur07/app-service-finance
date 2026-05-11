<?php

declare(strict_types=1);

namespace App\Inventory\Application;

use App\Inventory\Entity\Location;
use App\Inventory\Entity\StockSnapshot;
use App\Inventory\Enum\LocationType;
use App\Inventory\Enum\SnapshotSessionStatus;
use App\Inventory\Enum\StockSnapshotMappingStatus;
use App\Inventory\Enum\StockStatus;
use App\Inventory\Infrastructure\Normalizer\OzonProductStocksRawNormalizer;
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
        private MarketplaceFacade $marketplaceFacade,
        private LocationRepository $locationRepository,
        private StockSnapshotRepository $stockSnapshotRepository,
        private EntityManagerInterface $entityManager,
        private AppLogger $logger,
    ) {}

    public function __invoke(string $companyId, string $snapshotSessionId, MarketplaceType $source): void
    {
        $session = $this->sessionRepository->findByIdAndCompany($snapshotSessionId, $companyId);
        if ($session === null) {
            $this->logger->warning('Normalization skipped: session not found.', compact('companyId', 'snapshotSessionId'));
            return;
        }

        if ($session->getStatus() !== SnapshotSessionStatus::Completed) {
            $this->logger->warning('Normalization skipped: session is not completed.', [
                'companyId' => $companyId,
                'snapshotSessionId' => $snapshotSessionId,
                'status' => $session->getStatus()->value,
            ]);
            return;
        }

        if ($source !== MarketplaceType::OZON) {
            $this->logger->warning('Normalization skipped: source is not supported.', ['source' => $source->value]);
            return;
        }

        $rawSnapshots = $this->rawSnapshotRepository->findBySessionAndCompanyOrdered($snapshotSessionId, $companyId);
        if ($rawSnapshots === []) {
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

        if ($sourceSkus === []) {
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
            if (count($matches) === 1) {
                $mappingBySku[$sku] = ['status' => StockSnapshotMappingStatus::Mapped, 'listingId' => $matches[0]['id']];
                $mappedListingIds[] = $matches[0]['id'];
                continue;
            }

            $mappingBySku[$sku] = count($matches) > 1
                ? ['status' => StockSnapshotMappingStatus::Ambiguous, 'listingId' => null]
                : ['status' => StockSnapshotMappingStatus::Unmapped, 'listingId' => null];
        }

        $productsByListing = $this->marketplaceFacade->resolveListingsToProducts($companyId, array_values(array_unique($mappedListingIds)));

        foreach ($rawSnapshots as $rawSnapshot) {
            foreach ($rowsByRaw[$rawSnapshot->getId()] ?? [] as $row) {
                $mapping = $mappingBySku[$row->sourceSku] ?? ['status' => StockSnapshotMappingStatus::Unmapped, 'listingId' => null];
                $listingId = $mapping['listingId'];
                $productId = $listingId !== null ? ($productsByListing[$listingId] ?? null) : null;
                $location = $this->findOrCreateLocation($companyId, $source, $row->fulfillmentType);

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

    private function findOrCreateLocation(string $companyId, MarketplaceType $source, ?string $fulfillmentType): Location
    {
        $externalId = $fulfillmentType ?? 'unknown';
        $location = $this->locationRepository->findOneBy([
            'companyId' => $companyId,
            'externalSystem' => $source,
            'externalId' => $externalId,
        ]);

        if ($location !== null) {
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
}
