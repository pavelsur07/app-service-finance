<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use App\Marketplace\DTO\OzonCatalogItemDTO;
use App\Marketplace\DTO\OzonCatalogSyncResultDTO;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\OzonCatalogApiException;
use App\Marketplace\Infrastructure\Api\Ozon\OzonProductCatalogClient;
use App\Marketplace\Infrastructure\Normalizer\Ozon\OzonProductCatalogNormalizer;
use App\Marketplace\Infrastructure\Query\MarketplaceCredentialsQuery;
use App\Marketplace\Infrastructure\Query\OzonListingCatalogUpsertQuery;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Загрузка каталога товаров Ozon в листинги.
 *
 * Закрывает три дефекта разом:
 * 1. Товар без продаж был невидим — вход теперь `/v3/product/list`, а не список
 *    SKU из нашей БД.
 * 2. `items[].name` из `/v3/product/info/list` больше не выбрасывается.
 * 3. Листинг, созданный финансовым pipeline с `name = NULL`, обогащается:
 *    каталожный upsert делает `DO UPDATE`, а не `DO NOTHING`.
 *
 * Сопоставление идёт по ВСЕМУ множеству SKU товара (`sources[].sku`), а не по
 * верхнеуровневому: на реальной выгрузке 50 товаров дали 78 SKU, и листинг,
 * заведённый по FBS-схеме, находится только по вторичному sku.
 *
 * Транзакция — одна на информационный чанк (до 1000 товаров), не на весь
 * прогон. Глобальная держала бы блокировки на `marketplace_listings` всё время
 * обхода и мешала бы финансовому pipeline; отдельная транзакция на каждую
 * строку давала бы десятки тысяч autocommit-операций. Сбой на чанке N
 * сохраняет чанки 1..N-1: upsert идемпотентен, следующий прогон дозаполнит.
 */
final readonly class RefreshOzonListingCatalogAction
{
    public const RESOURCE_PRODUCT_LIST = 'ozon_seller_product_list';
    public const RESOURCE_PRODUCT_INFO = 'ozon_seller_product_info';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketplaceCredentialsQuery $credentialsQuery,
        private OzonProductCatalogClient $client,
        private OzonProductCatalogNormalizer $normalizer,
        private MarketplaceListingRepository $listingRepository,
        private OzonListingCatalogUpsertQuery $upsertQuery,
        private RawStorageFacade $rawStorageFacade,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId, string $connectionId): OzonCatalogSyncResultDTO
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);

        $company = $this->entityManager->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \InvalidArgumentException('Company not found.');
        }

        [$clientId, $apiKey] = $this->credentials($companyId, $connectionId);

        // Не SyncJob модуля Ingestion, а идентификатор одного прогона: он лишь
        // группирует объекты одной выгрузки в пути raw-хранилища.
        $runId = Uuid::uuid7()->toString();
        $fetchedAt = new \DateTimeImmutable();
        $lastSeenAt = $fetchedAt;

        $this->logger->info('[OzonListingCatalog] Sync started.', [
            'company_id' => $companyId,
            'connection_id' => $connectionId,
            'run_id' => $runId,
        ]);

        $productIds = [];
        $rawStored = 0;
        $page = 0;
        $reportedTotal = 0;

        foreach ($this->client->iterateProductListPages($clientId, $apiKey) as $pagePayload) {
            ++$page;
            $result = is_array($pagePayload['result'] ?? null) ? $pagePayload['result'] : [];
            $rawItems = is_array($result['items'] ?? null) ? $result['items'] : [];

            // Каталог, кратный размеру страницы, отдаёт пустую последнюю
            // страницу. Складывать её нечем: RawStorageFacade отвергает пустой
            // батч, а хранить нулевой объект незачем.
            if ([] !== $rawItems) {
                $rawStored += $this->storeRaw(
                    $companyId,
                    $connectionId,
                    self::RESOURCE_PRODUCT_LIST,
                    sprintf('page-%d', $page),
                    $runId,
                    $fetchedAt,
                    $rawItems,
                );
            }

            $pageIds = $this->normalizer->extractProductIds($pagePayload);
            $this->assertProductIdsUsable(
                count($rawItems),
                count($pageIds),
                ['company_id' => $companyId, 'page' => $page],
            );

            if (is_int($result['total'] ?? null)) {
                $reportedTotal = max($reportedTotal, $result['total']);
            }

            foreach ($pageIds as $productId) {
                $productIds[$productId] = true;
            }
        }

        $this->assertWalkComplete($reportedTotal, count($productIds), ['company_id' => $companyId]);

        $productsFetched = 0;
        $listingsUpserted = 0;
        $chunk = 0;

        foreach (array_chunk(array_keys($productIds), OzonProductCatalogClient::INFO_CHUNK_SIZE) as $productIdChunk) {
            ++$chunk;
            $payload = $this->client->fetchProductInfo($clientId, $apiKey, $productIdChunk);

            $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];

            // Raw кладём ДО интерпретации: если ответ окажется непригодным,
            // сохранённый payload — единственное свидетельство того, что
            // прислал Ozon. Пустой батч хранилище не принимает, а ниже
            // assertCardsUsable всё равно превратит его в ошибку.
            if ([] !== $rawItems) {
                $rawStored += $this->storeRaw(
                    $companyId,
                    $connectionId,
                    self::RESOURCE_PRODUCT_INFO,
                    sprintf('chunk-%d', $chunk),
                    $runId,
                    $fetchedAt,
                    $rawItems,
                );
            }

            $returned = count($rawItems);
            $items = $this->normalizer->normalize($payload);

            // Обрабатываем только карточки ЗАПРОШЕННЫХ товаров: ответ,
            // приехавший «не про тот чанк», не должен молча подменить выборку.
            //
            // Индексируем по product_id, а не фильтруем список: два дубля одной
            // карточки иначе считались бы за два товара и прятали бы третий,
            // который не приехал.
            $requestedIds = array_flip($productIdChunk);
            $byProductId = [];
            foreach ($items as $item) {
                if (null !== $item->productId && isset($requestedIds[$item->productId])) {
                    $byProductId[$item->productId] = $item;
                }
            }
            $items = array_values($byProductId);

            $this->assertCardsUsable(
                requested: count($productIdChunk),
                returned: $returned,
                usable: count($items),
                context: ['company_id' => $companyId, 'chunk' => $chunk],
            );

            $productsFetched += count($items);
            $listingsUpserted += $this->entityManager->wrapInTransaction(
                fn (): int => $this->applyToListings($company, $items, $lastSeenAt),
            );
        }

        $this->logger->info('[OzonListingCatalog] Sync finished.', [
            'company_id' => $companyId,
            'connection_id' => $connectionId,
            'run_id' => $runId,
            'products_fetched' => $productsFetched,
            'listings_upserted' => $listingsUpserted,
            'raw_records_stored' => $rawStored,
        ]);

        return new OzonCatalogSyncResultDTO($productsFetched, $listingsUpserted, $rawStored);
    }

    /**
     * Карточки товаров: считаем от числа ЗАПРОШЕННЫХ product_id, а не от числа
     * элементов ответа. Иначе `{"items":[]}` на непустой чанк даёт received = 0,
     * usable = 0, ни одно условие не срабатывает, и прогон молча отчитывается
     * успехом, не загрузив ничего.
     *
     * Полное отсутствие пригодных карточек — ошибка. Частичное — warning: один
     * недостающий или мусорный товар в каталоге на десять тысяч не должен
     * отменять ночную выгрузку, но и молчать о нём нельзя.
     *
     * @param array<string, mixed> $context
     */
    private function assertCardsUsable(int $requested, int $returned, int $usable, array $context): void
    {
        if ($requested > 0 && 0 === $usable) {
            throw new OzonCatalogApiException(sprintf('Ozon catalog /v3/product/info/list returned no usable card for %d requested products.', $requested));
        }

        if ($usable < $requested || $usable < $returned) {
            $this->logger->warning('[OzonListingCatalog] Some product cards are missing or unusable.', $context + [
                'endpoint' => '/v3/product/info/list',
                'requested' => $requested,
                'returned' => $returned,
                'usable' => $usable,
                'skipped' => max($requested, $returned) - $usable,
            ]);
        }
    }

    /**
     * Покрытие обхода: Ozon сам сообщает размер каталога в `result.total`.
     * Без сверки оборванная пагинация — пустая первая страница, преждевременный
     * пустой `last_id` — выглядела бы полной выгрузкой.
     *
     * Ноль собранных при непустом каталоге — ошибка. Недобор — warning:
     * собранное полезно, ронять прогон незачем, но расхождение должно быть
     * видно. Перебор расхождением не считается: каталог мог вырасти по ходу
     * обхода.
     *
     * @param array<string, mixed> $context
     */
    private function assertWalkComplete(int $reportedTotal, int $collected, array $context): void
    {
        if ($reportedTotal > 0 && 0 === $collected) {
            throw new OzonCatalogApiException(sprintf('Ozon catalog reports %d products but the walk collected none.', $reportedTotal));
        }

        if ($collected < $reportedTotal) {
            $this->logger->warning('[OzonListingCatalog] Catalog walk collected fewer products than reported.', $context + [
                'reported_total' => $reportedTotal,
                'collected' => $collected,
                'missing' => $reportedTotal - $collected,
            ]);
        }
    }

    /**
     * Страница списка товаров: элементы есть, а идентификаторов не извлеклось
     * ни одного — нарушение контракта, а не пустой каталог.
     *
     * @param array<string, mixed> $context
     */
    private function assertProductIdsUsable(int $received, int $usable, array $context): void
    {
        if ($received > 0 && 0 === $usable) {
            throw new OzonCatalogApiException(sprintf('Ozon catalog /v3/product/list returned %d items without a single product_id.', $received));
        }

        if ($usable < $received) {
            $this->logger->warning('[OzonListingCatalog] Some product list rows were skipped as unusable.', $context + [
                'endpoint' => '/v3/product/list',
                'received' => $received,
                'skipped' => $received - $usable,
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function credentials(string $companyId, string $connectionId): array
    {
        $credentials = $this->credentialsQuery->getCredentials(
            $companyId,
            MarketplaceType::OZON,
            MarketplaceConnectionType::SELLER,
            $connectionId,
        );

        $clientId = trim((string) ($credentials['client_id'] ?? ''));
        $apiKey = trim((string) ($credentials['api_key'] ?? ''));

        if ('' === $clientId || '' === $apiKey) {
            throw new OzonCatalogApiException(sprintf('Active Ozon SELLER credentials not found for connection %s.', $connectionId));
        }

        return [$clientId, $apiKey];
    }

    /**
     * @param list<OzonCatalogItemDTO> $items
     */
    private function applyToListings(Company $company, array $items, \DateTimeImmutable $lastSeenAt): int
    {
        if ([] === $items) {
            return 0;
        }

        $allSkus = [];
        foreach ($items as $item) {
            foreach ($item->marketplaceSkus as $sku) {
                $allSkus[$sku] = true;
            }
        }

        $existing = $this->listingRepository->findListingsBySkusIndexed(
            $company,
            MarketplaceType::OZON,
            array_keys($allSkus),
        );

        $companyId = (string) $company->getId();
        $upserted = 0;

        foreach ($items as $item) {
            // Обновляем КАЖДЫЙ существующий листинг товара: у товара с двумя
            // источниками их может быть два, с разными marketplace_sku.
            $targets = array_values(array_filter(
                $item->marketplaceSkus,
                static fn (string $sku): bool => isset($existing[$sku]),
            ));

            // Товара нет ни под одним SKU — заводим одну строку по основному.
            // Вторая появится сама при первой продаже по второй схеме, и
            // следующий прогон её обогатит; заводить обе сразу значило бы
            // удваивать таблицу мёртвыми строками.
            if ([] === $targets) {
                $targets = [$item->primarySku];
            }

            foreach ($targets as $sku) {
                // Считаем записанное, а не предпринятое: freshness-guard может
                // отклонить устаревший прогон, и завышенный счётчик прятал бы
                // его срабатывание.
                $upserted += $this->upsertQuery->upsert(
                    companyId: $companyId,
                    marketplaceSku: $sku,
                    name: $item->name,
                    supplierSku: $item->offerId,
                    marketplaceCreatedAt: $item->marketplaceCreatedAt,
                    lastSeenAt: $lastSeenAt,
                    marketplaceData: $item->marketplaceData,
                );
            }
        }

        return $upserted;
    }

    /**
     * @param array<array-key, mixed> $rows сырые элементы страницы ответа Ozon
     */
    private function storeRaw(
        string $companyId,
        string $connectionId,
        string $resourceType,
        string $externalId,
        string $runId,
        \DateTimeImmutable $fetchedAt,
        array $rows,
    ): int {
        $typedRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $typedRow */
            $typedRow = $row;
            $typedRows[] = $typedRow;
        }

        // Все элементы оказались скалярами: писать в raw нечего, а
        // RawStorageFacade отверг бы пустой батч своим исключением — диагноз
        // должно ставить доменное исключение каталога уровнем выше.
        if ([] === $typedRows) {
            return 0;
        }

        return count($this->rawStorageFacade->storeAndGetIds(new RawBatch(
            companyId: $companyId,
            connectionRef: $connectionId,
            // shopRef = connectionRef, как в OzonSellerReportConnector::discoverShops().
            // Client-Id сюда не кладём: он идентифицирует аккаунт продавца и
            // осел бы в путях объектного хранилища.
            shopRef: $connectionId,
            source: IngestSource::OZON,
            resourceType: $resourceType,
            externalId: $externalId,
            syncJobId: $runId,
            fetchedAt: $fetchedAt,
            rows: $typedRows,
        )));
    }
}
