<?php

declare(strict_types=1);

namespace App\Inventory\Application;

use App\Inventory\Application\DTO\OzonSuppliesSyncResult;
use App\Inventory\Application\DTO\OzonSupplyItem;
use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Entity\Location;
use App\Inventory\Entity\StockSnapshot;
use App\Inventory\Enum\LocationType;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Inventory\Enum\StockSnapshotMappingStatus;
use App\Inventory\Enum\StockStatus;
use App\Inventory\Infrastructure\Api\OzonFetcher\OzonSupplySnapshot;
use App\Inventory\Infrastructure\Api\OzonFetcher\OzonSupplySnapshotClient;
use App\Inventory\Infrastructure\Normalizer\OzonSupplyOrdersParser;
use App\Inventory\Repository\LocationRepository;
use App\Inventory\Repository\StockSnapshotRepository;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

/**
 * Поставки Ozon в пути (docs/tasks/ozon-supplies-in-transit): последний снимок `ozon.supplies`
 * из ozon-fetcher → строки остатков `in_supply` за день снимка.
 *
 * Строки пишутся в сессию дневного снимка остатков той же даты (её id и snapshot_at): запросы
 * «последняя сессия дня» (InventoryFacade, перенормализация) выбирают ту же сессию, что и без
 * поставок, а фильтр `status = available` поставки не видит. Сырьё — отдельной сессией.
 * HTTP — до транзакции; запись дня — замена окна (DELETE + INSERT) в одной транзакции, коммитит Action.
 */
final readonly class SyncOzonSuppliesAction
{
    /** Снимок старше — не подставляется за сегодня. */
    public const MAX_AGE_DAYS = 2;
    public const UNKNOWN_WAREHOUSE_NAME = 'Ozon — поставки';
    private const LOCATION_PREFIX = 'supply';

    public function __construct(
        private OzonSupplySnapshotClient $client,
        private OzonSupplyOrdersParser $parser,
        private StockSnapshotRepository $stockSnapshotRepository,
        private LocationRepository $locationRepository,
        private MarketplaceFacade $marketplaceFacade,
        private EntityManagerInterface $entityManager,
        private AppLogger $logger,
    ) {
    }

    public function __invoke(string $companyId, string $connectionId, \DateTimeImmutable $today): OzonSuppliesSyncResult
    {
        Assert::uuid($companyId);
        if (!$this->client->isEnabled()) {
            return new OzonSuppliesSyncResult(OzonSuppliesSyncResult::DISABLED);
        }

        $snapshot = $this->client->fetchLatest($connectionId);
        if (null === $snapshot) {
            $this->logger->warning('Ozon supplies skipped: no snapshot in ozon-fetcher.', ['companyId' => $companyId, 'connectionId' => $connectionId]);

            return new OzonSuppliesSyncResult(OzonSuppliesSyncResult::NO_SNAPSHOT);
        }
        $date = $snapshot->periodDate->format('Y-m-d');
        if ($snapshot->periodDate < $today->setTime(0, 0)->modify(sprintf('-%d days', self::MAX_AGE_DAYS))) {
            $this->logger->warning('Ozon supplies skipped: snapshot is stale.', ['companyId' => $companyId, 'snapshotDate' => $date]);

            return new OzonSuppliesSyncResult(OzonSuppliesSyncResult::STALE, $date);
        }
        $stockSession = $this->stockSnapshotRepository->findLatestAvailableDaySession($companyId, MarketplaceType::OZON, $snapshot->periodDate);
        if (null === $stockSession) {
            $this->logger->warning('Ozon supplies skipped: no stock snapshot for the day.', ['companyId' => $companyId, 'snapshotDate' => $date]);

            return new OzonSuppliesSyncResult(OzonSuppliesSyncResult::NO_STOCK_SESSION, $date);
        }

        $items = $this->parser->parse($snapshot->pages);
        $rows = $this->aggregate($items);
        $mapping = $this->mapping($companyId, array_values(array_unique(array_column($rows, 'sku'))));

        $quantity = '0';
        $this->entityManager->wrapInTransaction(function () use ($companyId, $connectionId, $snapshot, $stockSession, $rows, $mapping, &$quantity): void {
            $raw = $this->storeRaw($companyId, $connectionId, $snapshot);
            $this->stockSnapshotRepository->deleteDayRowsByStatus($companyId, MarketplaceType::OZON, $snapshot->periodDate, StockStatus::InSupply);
            $locations = $this->locations($companyId, $rows);
            $this->entityManager->flush();

            foreach ($rows as $row) {
                $map = $mapping[$row['sku']];
                $this->stockSnapshotRepository->upsertDaySnapshot(new StockSnapshot(
                    companyId: $companyId,
                    snapshotSessionId: $stockSession['sessionId'],
                    snapshotDate: $snapshot->periodDate,
                    snapshotAt: $stockSession['snapshotAt'],
                    locationId: $locations[$row['locationKey']]->getId(),
                    status: StockStatus::InSupply,
                    quantity: $row['quantity'],
                    reservedQuantity: '0',
                    source: MarketplaceType::OZON,
                    rawSnapshotId: $raw->getId(),
                    listingId: $map['listingId'],
                    productId: $map['productId'],
                    sourceSku: $row['sku'],
                    sourceOfferId: $row['offerId'],
                    fulfillmentType: 'fbo',
                    mappingStatus: $map['status'],
                ));
                $quantity = bcadd($quantity, $row['quantity'], 3);
            }
        });

        $this->logger->info('Ozon supplies written.', [
            'companyId' => $companyId, 'snapshotDate' => $date, 'items' => count($items), 'rows' => count($rows), 'quantity' => $quantity,
        ]);

        return new OzonSuppliesSyncResult(OzonSuppliesSyncResult::WRITTEN, $date, count($items), count($rows), $quantity);
    }

    /**
     * Позиции → строка на (SKU, склад назначения): ключ уникальности строк дня — SKU × локация × статус.
     *
     * @param list<OzonSupplyItem> $items
     *
     * @return list<array{sku: string, offerId: ?string, quantity: string, locationKey: string, warehouseName: ?string}>
     */
    private function aggregate(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $locationKey = null === $item->warehouseId ? self::LOCATION_PREFIX : self::LOCATION_PREFIX.':'.$item->warehouseId;
            $key = $item->sku."\0".$locationKey;
            $rows[$key] ??= ['sku' => $item->sku, 'offerId' => $item->offerId, 'quantity' => '0', 'locationKey' => $locationKey, 'warehouseName' => $item->warehouseName];
            $rows[$key]['quantity'] = bcadd($rows[$key]['quantity'], $item->quantity, 3);
        }

        return array_values($rows);
    }

    /**
     * Сопоставление SKU с листингом — тем же правилом, что у остатков Ozon: ровно один листинг.
     *
     * @param list<string> $skus
     *
     * @return array<string, array{status: StockSnapshotMappingStatus, listingId: ?string, productId: ?string}>
     */
    private function mapping(string $companyId, array $skus): array
    {
        $listingsBySku = [] === $skus ? [] : $this->marketplaceFacade->findListingsByMarketplaceSkus($companyId, MarketplaceType::OZON->value, $skus);
        $listingIds = [];
        foreach ($skus as $sku) {
            if (1 === count($listingsBySku[$sku] ?? [])) {
                $listingIds[] = $listingsBySku[$sku][0]['id'];
            }
        }
        $products = [] === $listingIds ? [] : $this->marketplaceFacade->resolveListingsToProducts($companyId, $listingIds);

        $mapping = [];
        foreach ($skus as $sku) {
            $matches = $listingsBySku[$sku] ?? [];
            $listingId = 1 === count($matches) ? $matches[0]['id'] : null;
            $mapping[$sku] = [
                'status' => match (true) {
                    null !== $listingId => StockSnapshotMappingStatus::Mapped,
                    count($matches) > 1 => StockSnapshotMappingStatus::Ambiguous,
                    default => StockSnapshotMappingStatus::Unmapped,
                },
                'listingId' => $listingId,
                'productId' => null !== $listingId ? ($products[$listingId] ?? null) : null,
            ];
        }

        return $mapping;
    }

    /**
     * @param list<array{sku: string, offerId: ?string, quantity: string, locationKey: string, warehouseName: ?string}> $rows
     *
     * @return array<string, Location>
     */
    private function locations(string $companyId, array $rows): array
    {
        $names = array_column($rows, 'warehouseName', 'locationKey');
        $locations = $this->locationRepository->findByCompanySourceAndExternalIds($companyId, MarketplaceType::OZON, array_map('strval', array_keys($names)));
        foreach ($names as $key => $name) {
            $key = (string) $key;
            if (isset($locations[$key])) {
                continue;
            }
            $locations[$key] = new Location(
                companyId: $companyId,
                type: LocationType::MpAcceptance,
                externalSystem: MarketplaceType::OZON,
                code: mb_strtoupper(mb_substr($key, 0, 50)),
                name: null === $name ? self::UNKNOWN_WAREHOUSE_NAME : 'Поставка: '.$name,
                externalId: $key,
            );
            $this->entityManager->persist($locations[$key]);
        }

        return $locations;
    }

    private function storeRaw(string $companyId, string $connectionId, OzonSupplySnapshot $snapshot): InventoryRawSnapshot
    {
        $session = new InventorySnapshotSession($companyId, MarketplaceType::OZON, SnapshotTriggerType::ScheduledDay, expectedPages: 1);
        $session->markInProgress();
        $session->incrementReceivedPages();
        $session->markCompleted();
        $this->entityManager->persist($session);

        $raw = new InventoryRawSnapshot(
            companyId: $companyId,
            snapshotSessionId: $session->getId(),
            source: MarketplaceType::OZON,
            sourceEndpoint: 'ozon-fetcher '.OzonSupplySnapshotClient::ENDPOINT.' '.OzonSupplySnapshotClient::RESOURCE,
            requestParams: ['connectionId' => $connectionId, 'periodKey' => $snapshot->periodDate->format('Y-m-d')],
            responseStatus: 200,
            responseBody: $snapshot->raw,
            fetchedAt: $snapshot->loadedAt,
            fetchDurationMs: 0,
            correlationId: $session->getCorrelationId(),
            pageNumber: 1,
        );
        $raw->markAsProcessed();
        $this->entityManager->persist($raw);

        return $raw;
    }
}
