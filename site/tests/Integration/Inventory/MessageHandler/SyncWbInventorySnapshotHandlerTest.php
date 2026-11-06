<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\MessageHandler;

use App\Company\Entity\Company;
use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Enum\SnapshotSessionStatus;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Inventory\Infrastructure\Api\Wildberries\WbInventoryClient;
use App\Inventory\Message\NormalizeInventorySnapshotMessage;
use App\Inventory\Message\SyncWbInventorySnapshotMessage;
use App\Inventory\MessageHandler\NormalizeInventorySnapshotHandler;
use App\Inventory\MessageHandler\SyncWbInventorySnapshotHandler;
use App\Inventory\Repository\InventoryRawSnapshotRepository;
use App\Inventory\Repository\InventorySnapshotSessionRepository;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Shared\Service\AppLogger;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SyncWbInventorySnapshotHandlerTest extends IntegrationTestCase
{
    public function testTwoSizesAreFetchedAndMappedIndependently(): void
    {
        $company = $this->createCompany(701);
        $connection = $this->createConnection($company, 701);
        $session = $this->createSession($company);
        $this->swapHttpClient([
            $this->catalogResponse([
                $this->card(100, [[1001, 'S', 'barcode-s'], [1002, 'M', 'barcode-m']]),
            ]),
            $this->catalogResponse([]),
            $this->inventoryResponse([
                $this->stock(100, 1001, 507, 4),
                $this->stock(100, 1002, 507, 7),
            ]),
            $this->sellerWarehousesResponse([]),
        ]);

        ($this->handler())($this->message($company, $connection, $session));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Completed, $session->getStatus());
        $normalizeMessage = $this->normalizeMessage($session, $company);
        self::getContainer()->get(NormalizeInventorySnapshotHandler::class)($normalizeMessage);

        $rows = $this->connection->fetchAllAssociative(
            "SELECT source_sku, listing_id, quantity FROM inventory_stock_snapshots WHERE snapshot_session_id = :session AND status = 'available' ORDER BY source_sku",
            ['session' => $session->getId()],
        );
        self::assertSame(['1001', '1002'], array_column($rows, 'source_sku'));
        self::assertSame(['4.000', '7.000'], array_column($rows, 'quantity'));
        self::assertNotSame($rows[0]['listing_id'], $rows[1]['listing_id']);
        self::assertNotNull($rows[0]['listing_id']);
        self::assertNotNull($rows[1]['listing_id']);
    }

    public function testTrashCardSizesAreMappedToDistinctInactiveListings(): void
    {
        $company = $this->createCompany(710);
        $connection = $this->createConnection($company, 710);
        $session = $this->createSession($company);
        $this->swapHttpClient([
            $this->catalogResponse([]),
            $this->catalogResponse([
                $this->card(110, [[1101, 'S', 'trash-barcode-s'], [1102, 'M', 'trash-barcode-m']]),
            ]),
            $this->inventoryResponse([
                $this->stock(110, 1101, 507, 5),
                $this->stock(110, 1102, 507, 8),
            ]),
            $this->sellerWarehousesResponse([]),
        ]);

        ($this->handler())($this->message($company, $connection, $session));
        self::getContainer()->get(NormalizeInventorySnapshotHandler::class)($this->normalizeMessage($session, $company));

        $rows = $this->connection->fetchAllAssociative(
            "SELECT stock.source_sku, stock.listing_id, listing.is_active FROM inventory_stock_snapshots stock JOIN marketplace_listings listing ON listing.id = stock.listing_id WHERE stock.snapshot_session_id = :session AND stock.status = 'available' ORDER BY stock.source_sku",
            ['session' => $session->getId()],
        );
        self::assertSame(['1101', '1102'], array_column($rows, 'source_sku'));
        self::assertNotSame($rows[0]['listing_id'], $rows[1]['listing_id']);
        self::assertFalse($rows[0]['is_active']);
        self::assertFalse($rows[1]['is_active']);
    }

    public function testSeveralPagesUseIncreasingOffsets(): void
    {
        $company = $this->createCompany(702);
        $connection = $this->createConnection($company, 702);
        $session = $this->createSession($company);
        $this->swapHttpClient([
            $this->catalogResponse([$this->card(200, [[2001, 'S', 'barcode-702']])]),
            $this->catalogResponse([]),
            $this->inventoryResponse([$this->stock(200, 2001, 1, 1), $this->stock(200, 2001, 2, 2)]),
            $this->inventoryResponse([$this->stock(200, 2001, 3, 3)]),
            $this->sellerWarehousesResponse([]),
        ]);

        ($this->handler(2))($this->message($company, $connection, $session));

        $raw = $this->rawSnapshots($session);
        self::assertCount(2, $raw);
        self::assertSame([0, 2], array_map(static fn (InventoryRawSnapshot $item): int => $item->getRequestParams()['offset'], $raw));
        self::assertSame([1, 2], array_map(static fn (InventoryRawSnapshot $item): int => $item->getPageNumber(), $raw));
    }

    public function testRepeatedPageMarksPartialWithoutNormalization(): void
    {
        $company = $this->createCompany(703);
        $connection = $this->createConnection($company, 703);
        $session = $this->createSession($company);
        $firstPage = [$this->stock(300, 3001, 1, 1)];
        $this->swapHttpClient([
            $this->catalogResponse([$this->card(300, [[3001, 'S', 'barcode-703']])]),
            $this->catalogResponse([]),
            $this->inventoryResponse($firstPage),
            $this->inventoryResponse([$this->stock(300, 3001, 2, 2)]),
            $this->inventoryResponse($firstPage),
        ]);

        ($this->handler(1))($this->message($company, $connection, $session));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Partial, $session->getStatus());
        self::assertCount(2, $this->rawSnapshots($session));
        self::assertSame(0, $this->countNormalizeMessages($session, $company));
    }

    public function testRateLimitBeforeFirstPageMarksFailed(): void
    {
        $company = $this->createCompany(704);
        $connection = $this->createConnection($company, 704);
        $session = $this->createSession($company);
        $this->swapHttpClient([
            $this->catalogResponse([$this->card(400, [[4001, 'S', 'barcode-704']])]),
            $this->catalogResponse([]),
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after: 10']]),
        ]);

        ($this->handler())($this->message($company, $connection, $session));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Failed, $session->getStatus());
        self::assertCount(0, $this->rawSnapshots($session));
        self::assertSame(0, $this->countNormalizeMessages($session, $company));
    }

    public function testRateLimitAfterFirstPageMarksPartial(): void
    {
        $company = $this->createCompany(706);
        $connection = $this->createConnection($company, 706);
        $session = $this->createSession($company);
        $this->swapHttpClient([
            $this->catalogResponse([$this->card(600, [[6001, 'S', 'barcode-706']])]),
            $this->catalogResponse([]),
            $this->inventoryResponse([$this->stock(600, 6001, 1, 1)]),
            new MockResponse('', ['http_code' => 429]),
        ]);

        ($this->handler(1))($this->message($company, $connection, $session));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Partial, $session->getStatus());
        self::assertCount(1, $this->rawSnapshots($session));
        self::assertSame(0, $this->countNormalizeMessages($session, $company));
    }

    public function testMissingCredentialsMarksFailedWithoutHttpRequest(): void
    {
        $company = $this->createCompany(707);
        $session = $this->createSession($company);
        $connection = new MarketplaceConnection(
            '55555555-5555-4555-8555-000000000707',
            $company,
            MarketplaceType::WILDBERRIES,
            MarketplaceConnectionType::SELLER,
        );

        ($this->handler())($this->message($company, $connection, $session));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Failed, $session->getStatus());
        self::assertCount(0, $this->rawSnapshots($session));
    }

    public function testCatalogFailureStopsBeforeInventoryFetch(): void
    {
        $company = $this->createCompany(705);
        $connection = $this->createConnection($company, 705);
        $session = $this->createSession($company);
        $this->swapHttpClient([new MockResponse('', ['http_code' => 503])]);

        ($this->handler())($this->message($company, $connection, $session));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Failed, $session->getStatus());
        self::assertCount(0, $this->rawSnapshots($session));
    }

    public function testInProgressRedeliveryResumesAfterPersistedPage(): void
    {
        $company = $this->createCompany(708);
        $connection = $this->createConnection($company, 708);
        $session = $this->createSession($company);
        $session->markInProgress();
        $session->incrementReceivedPages();
        $this->em->persist(new InventoryRawSnapshot(
            companyId: $company->getId(),
            snapshotSessionId: $session->getId(),
            source: MarketplaceType::WILDBERRIES,
            sourceEndpoint: WbInventoryClient::ENDPOINT,
            requestParams: ['offset' => 0, 'limit' => 2],
            responseStatus: 200,
            responseBody: ['data' => ['items' => [
                $this->stock(800, 8001, 1, 1),
                $this->stock(800, 8002, 1, 2),
            ]]],
            fetchedAt: new \DateTimeImmutable(),
            fetchDurationMs: 1,
            correlationId: $session->getCorrelationId(),
            pageNumber: 1,
        ));
        $this->em->flush();
        $this->swapHttpClient([
            $this->catalogResponse([$this->card(800, [[8001, 'S', 'barcode-708-s'], [8002, 'M', 'barcode-708-m'], [8003, 'L', 'barcode-708-l']])]),
            $this->catalogResponse([]),
            $this->inventoryResponse([$this->stock(800, 8003, 1, 3)]),
            $this->sellerWarehousesResponse([]),
        ]);

        ($this->handler(2))($this->message($company, $connection, $session));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Completed, $session->getStatus());
        self::assertSame(2, $session->getReceivedPages());
        $raw = $this->rawSnapshots($session);
        self::assertCount(2, $raw);
        self::assertSame([0, 2], array_map(static fn (InventoryRawSnapshot $item): int => $item->getRequestParams()['offset'], $raw));
        self::assertSame([1, 2], array_map(static fn (InventoryRawSnapshot $item): int => $item->getPageNumber(), $raw));
        self::assertSame(1, $this->countNormalizeMessages($session, $company));
    }

    public function testCompletedRedeliveryRetriesFailedNormalizationDispatch(): void
    {
        $company = $this->createCompany(709);
        $connection = $this->createConnection($company, 709);
        $session = $this->createSession($company);
        $this->swapHttpClient([
            $this->catalogResponse([$this->card(900, [[9001, 'S', 'barcode-709']])]),
            $this->catalogResponse([]),
            $this->inventoryResponse([$this->stock(900, 9001, 1, 1)]),
            $this->sellerWarehousesResponse([]),
        ]);
        $failingBus = $this->createMock(MessageBusInterface::class);
        $failingBus->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('Transport unavailable.'));

        try {
            ($this->handler(messageBus: $failingBus))($this->message($company, $connection, $session));
            self::fail('Normalization dispatch failure must be retried by Messenger.');
        } catch (\RuntimeException $e) {
            self::assertSame('Transport unavailable.', $e->getMessage());
        }

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Completed, $session->getStatus());
        self::assertCount(1, $this->rawSnapshots($session));

        ($this->handler())($this->message($company, $connection, $session));

        self::assertSame(1, $this->countNormalizeMessages($session, $company));
    }

    public function testSellerWarehouseStocksAreFetchedAndNormalizedAsFbs(): void
    {
        $company = $this->createCompany(720);
        $connection = $this->createConnection($company, 720);
        $session = $this->createSession($company);
        $this->swapHttpClient([
            $this->catalogResponse([$this->card(120, [[1201, 'S', 'barcode-720-s']])]),
            $this->catalogResponse([]),
            $this->inventoryResponse([$this->stock(120, 1201, 507, 4)]),
            $this->sellerWarehousesResponse([['id' => 7, 'name' => 'Мой склад']]),
            $this->sellerStocksResponse([['sku' => 'barcode-720-s', 'amount' => 3]]),
        ]);

        ($this->handler())($this->message($company, $connection, $session));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Completed, $session->getStatus());
        $raw = $this->rawSnapshots($session);
        self::assertCount(2, $raw);
        self::assertSame(WbInventoryClient::SELLER_STOCKS_ENDPOINT.'/7', $raw[1]->getSourceEndpoint());
        self::assertSame(1, $raw[1]->getRequestParams()['chunk']);
        self::assertSame(7, $raw[1]->getRequestParams()['warehouseId']);
        self::assertSame(2, $raw[1]->getPageNumber());

        self::getContainer()->get(NormalizeInventorySnapshotHandler::class)($this->normalizeMessage($session, $company));

        $rows = $this->connection->fetchAllAssociative(
            "SELECT s.source_sku, s.source_offer_id, s.listing_id, s.quantity, s.fulfillment_type, s.mapping_status,
                    l.type AS location_type, l.external_id AS location_external_id, l.code AS location_code, l.name AS location_name
               FROM inventory_stock_snapshots s
               JOIN inventory_locations l ON l.id = s.location_id
              WHERE s.snapshot_session_id = :session AND s.status = 'available'
              ORDER BY s.fulfillment_type",
            ['session' => $session->getId()],
        );
        self::assertCount(2, $rows);
        [$fbs, $fbw] = $rows;
        self::assertSame('fbw', $fbw['fulfillment_type']);
        self::assertSame('1201', $fbw['source_sku']);
        self::assertSame('4.000', $fbw['quantity']);

        self::assertSame('fbs', $fbs['fulfillment_type']);
        self::assertSame('barcode-720-s', $fbs['source_sku']);
        self::assertSame('120', $fbs['source_offer_id']);
        self::assertSame('3.000', $fbs['quantity']);
        self::assertSame('mapped', $fbs['mapping_status']);
        self::assertNotNull($fbs['listing_id']);
        self::assertSame($fbw['listing_id'], $fbs['listing_id']);
        self::assertSame('seller_warehouse', $fbs['location_type']);
        self::assertSame('seller:7', $fbs['location_external_id']);
        self::assertSame('WB-FBS-7', $fbs['location_code']);
        self::assertSame('Мой склад', $fbs['location_name']);
    }

    public function testSellerWarehouseAccessDeniedKeepsFbwResultAndCompletesSession(): void
    {
        $company = $this->createCompany(721);
        $connection = $this->createConnection($company, 721);
        $session = $this->createSession($company);
        $httpClient = new MockHttpClient([
            $this->catalogResponse([$this->card(121, [[1211, 'S', 'barcode-721-s']])]),
            $this->catalogResponse([]),
            $this->inventoryResponse([$this->stock(121, 1211, 507, 4)]),
            new MockResponse('{"title":"forbidden"}', ['http_code' => 403]),
        ]);
        self::getContainer()->set('http_client', $httpClient);

        ($this->handler())($this->message($company, $connection, $session));

        // Запрос складов продавца ушёл и получил 403 — сессия всё равно завершена данными FBW.
        self::assertSame(4, $httpClient->getRequestsCount());
        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Completed, $session->getStatus());
        $raw = $this->rawSnapshots($session);
        self::assertCount(1, $raw);
        self::assertSame(WbInventoryClient::ENDPOINT, $raw[0]->getSourceEndpoint());

        self::getContainer()->get(NormalizeInventorySnapshotHandler::class)($this->normalizeMessage($session, $company));

        $types = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT fulfillment_type FROM inventory_stock_snapshots WHERE snapshot_session_id = :session',
            ['session' => $session->getId()],
        );
        self::assertSame(['fbw'], $types);
    }

    public function testNoBarcodesMeansNoMarketplaceApiRequests(): void
    {
        $company = $this->createCompany(722);
        $connection = $this->createConnection($company, 722);
        $session = $this->createSession($company);
        $responses = [
            $this->catalogResponse([]),
            $this->catalogResponse([]),
            $this->inventoryResponse([]),
        ];
        self::getContainer()->set('http_client', new MockHttpClient(static function (string $method, string $url) use (&$responses): MockResponse {
            self::assertStringNotContainsString('marketplace-api', $url, 'Без баркодов запросов в marketplace-api быть не должно.');
            $next = array_shift($responses);
            self::assertNotNull($next, sprintf('Unexpected request %s %s', $method, $url));

            return $next;
        }));

        ($this->handler())($this->message($company, $connection, $session));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Completed, $session->getStatus());
        self::assertSame([], $responses);
        foreach ($this->rawSnapshots($session) as $rawSnapshot) {
            self::assertStringStartsNotWith(WbInventoryClient::SELLER_STOCKS_ENDPOINT, $rawSnapshot->getSourceEndpoint());
        }
    }

    public function testRedeliveryAfterSellerPhaseDoesNotDuplicateSellerPages(): void
    {
        $company = $this->createCompany(723);
        $connection = $this->createConnection($company, 723);
        $session = $this->createSession($company);
        $session->markInProgress();
        $session->incrementReceivedPages(2);
        $this->em->persist(new InventoryRawSnapshot(
            companyId: (string) $company->getId(),
            snapshotSessionId: $session->getId(),
            source: MarketplaceType::WILDBERRIES,
            sourceEndpoint: WbInventoryClient::ENDPOINT,
            requestParams: ['offset' => 0, 'limit' => 2],
            responseStatus: 200,
            responseBody: ['data' => ['items' => [$this->stock(123, 1231, 1, 1)]]],
            fetchedAt: new \DateTimeImmutable(),
            fetchDurationMs: 1,
            correlationId: $session->getCorrelationId(),
            pageNumber: 1,
        ));
        $this->em->persist(new InventoryRawSnapshot(
            companyId: (string) $company->getId(),
            snapshotSessionId: $session->getId(),
            source: MarketplaceType::WILDBERRIES,
            sourceEndpoint: WbInventoryClient::SELLER_STOCKS_ENDPOINT.'/7',
            requestParams: ['warehouseId' => 7, 'warehouseName' => 'Мой склад', 'chunk' => 1],
            responseStatus: 200,
            responseBody: ['stocks' => [['sku' => 'barcode-723', 'amount' => 2]]],
            fetchedAt: new \DateTimeImmutable(),
            fetchDurationMs: 1,
            correlationId: $session->getCorrelationId(),
            pageNumber: 2,
        ));
        $this->em->flush();
        // Повторная доставка: каталог обновлять не нужно, страницы есть — ни одного HTTP-запроса.
        self::getContainer()->set('http_client', new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::fail(sprintf('Unexpected request %s %s', $method, $url));
        }));

        ($this->handler(2))($this->message($company, $connection, $session));

        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Completed, $session->getStatus());
        self::assertCount(2, $this->rawSnapshots($session));
        self::assertSame(1, $this->countNormalizeMessages($session, $company));
    }

    public function testNumericBarcodesAreSentToSellerStocksAsStrings(): void
    {
        $company = $this->createCompany(724);
        $connection = $this->createConnection($company, 724);
        $session = $this->createSession($company);
        $responses = [
            $this->catalogResponse([$this->card(124, [[1241, 'S', '4601234567890']])]),
            $this->catalogResponse([]),
            $this->inventoryResponse([$this->stock(124, 1241, 507, 4)]),
            $this->sellerWarehousesResponse([['id' => 7, 'name' => 'Мой склад']]),
            $this->sellerStocksResponse([['sku' => '4601234567890', 'amount' => 3]]),
        ];
        $stocksRequestBody = null;
        self::getContainer()->set('http_client', new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$stocksRequestBody): MockResponse {
            if (str_contains($url, WbInventoryClient::SELLER_STOCKS_ENDPOINT.'/')) {
                $stocksRequestBody = $options['body'];
            }
            $next = array_shift($responses);
            self::assertNotNull($next, sprintf('Unexpected request %s %s', $method, $url));

            return $next;
        }));

        ($this->handler())($this->message($company, $connection, $session));

        self::assertIsString($stocksRequestBody);
        // Числовой баркод уходит в WB строкой: PHP превращает такие ключи массива в int.
        self::assertSame(['skus' => ['4601234567890']], json_decode($stocksRequestBody, true, 512, \JSON_THROW_ON_ERROR));
        $this->em->refresh($session);
        self::assertSame(SnapshotSessionStatus::Completed, $session->getStatus());
    }

    public function testSellerPhaseFailureAfterFirstPageDiscardsSellerPages(): void
    {
        $company = $this->createCompany(725);
        $connection = $this->createConnection($company, 725);
        $session = $this->createSession($company);
        $this->swapHttpClient([
            $this->catalogResponse([$this->card(125, [[1251, 'S', 'barcode-725-s']])]),
            $this->catalogResponse([]),
            $this->inventoryResponse([$this->stock(125, 1251, 507, 4)]),
            $this->sellerWarehousesResponse([['id' => 7, 'name' => 'Склад А'], ['id' => 8, 'name' => 'Склад Б']]),
            $this->sellerStocksResponse([['sku' => 'barcode-725-s', 'amount' => 3]]),
            new MockResponse('', ['http_code' => 429]),
        ]);

        ($this->handler())($this->message($company, $connection, $session));

        $this->em->refresh($session);
        // Половина складов продавца — не остаток склада продавца: FBS-страницы этой ночи не сохраняются,
        // отчёт в режиме FBS покажет предыдущий полный день.
        self::assertSame(SnapshotSessionStatus::Completed, $session->getStatus());
        self::assertSame(1, $session->getReceivedPages());
        $raw = $this->rawSnapshots($session);
        self::assertCount(1, $raw);
        self::assertSame(WbInventoryClient::ENDPOINT, $raw[0]->getSourceEndpoint());

        self::getContainer()->get(NormalizeInventorySnapshotHandler::class)($this->normalizeMessage($session, $company));

        $types = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT fulfillment_type FROM inventory_stock_snapshots WHERE snapshot_session_id = :session',
            ['session' => $session->getId()],
        );
        self::assertSame(['fbw'], $types);
    }

    public function testSellerStocksPageWithoutStocksListDiscardsWholeSellerPhase(): void
    {
        $company = $this->createCompany(726);
        $connection = $this->createConnection($company, 726);
        $session = $this->createSession($company);
        $this->swapHttpClient([
            $this->catalogResponse([$this->card(126, [[1261, 'S', 'barcode-726-s']])]),
            $this->catalogResponse([]),
            $this->inventoryResponse([$this->stock(126, 1261, 507, 4)]),
            $this->sellerWarehousesResponse([['id' => 7, 'name' => 'Склад А'], ['id' => 8, 'name' => 'Склад Б']]),
            $this->sellerStocksResponse([['sku' => 'barcode-726-s', 'amount' => 3]]),
            new MockResponse('{}', ['http_code' => 200]),
        ]);

        ($this->handler())($this->message($company, $connection, $session));

        $this->em->refresh($session);
        // HTTP 200 без списка stocks — нарушение контракта, а не «пустой склад»: фаза отбрасывается целиком.
        self::assertSame(SnapshotSessionStatus::Completed, $session->getStatus());
        $raw = $this->rawSnapshots($session);
        self::assertCount(1, $raw);
        self::assertSame(WbInventoryClient::ENDPOINT, $raw[0]->getSourceEndpoint());
    }

    private function handler(int $pageLimit = WbInventoryClient::DEFAULT_LIMIT, ?MessageBusInterface $messageBus = null): SyncWbInventorySnapshotHandler
    {
        return new SyncWbInventorySnapshotHandler(
            self::getContainer()->get(InventorySnapshotSessionRepository::class),
            self::getContainer()->get(InventoryRawSnapshotRepository::class),
            self::getContainer()->get(MarketplaceFacade::class),
            self::getContainer()->get(WbInventoryClient::class),
            $this->em,
            $messageBus ?? self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get(AppLogger::class),
            $pageLimit,
        );
    }

    private function createCompany(int $index): Company
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function createConnection(Company $company, int $index): MarketplaceConnection
    {
        $connection = new MarketplaceConnection(
            sprintf('55555555-5555-4555-8555-%012d', $index),
            $company,
            MarketplaceType::WILDBERRIES,
            MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-token-'.$index)->setIsActive(true);
        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }

    private function createSession(Company $company): InventorySnapshotSession
    {
        $session = new InventorySnapshotSession($company->getId(), MarketplaceType::WILDBERRIES, SnapshotTriggerType::Manual);
        $this->em->persist($session);
        $this->em->flush();

        return $session;
    }

    private function message(Company $company, MarketplaceConnection $connection, InventorySnapshotSession $session): SyncWbInventorySnapshotMessage
    {
        return new SyncWbInventorySnapshotMessage($company->getId(), $connection->getId(), $session->getId(), SnapshotTriggerType::Manual->value);
    }

    /** @param list<MockResponse> $responses */
    private function swapHttpClient(array $responses): void
    {
        self::getContainer()->set('http_client', new MockHttpClient($responses));
    }

    /** @param list<array<string, mixed>> $cards */
    private function catalogResponse(array $cards): MockResponse
    {
        return new MockResponse(json_encode(['cards' => $cards, 'cursor' => ['total' => count($cards)]], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    /** @param list<array{0: int, 1: string, 2: string}> $sizes */
    private function card(int $nmId, array $sizes): array
    {
        return [
            'nmID' => $nmId,
            'vendorCode' => 'vendor-'.$nmId,
            'brand' => 'Brand',
            'subjectName' => 'Subject',
            'title' => 'Title',
            'sizes' => array_map(static fn (array $size): array => [
                'chrtID' => $size[0],
                'techSize' => $size[1],
                'skus' => [$size[2]],
            ], $sizes),
        ];
    }

    /** @return array<string, int|string> */
    private function stock(int $nmId, int $chrtId, int $warehouseId, int $quantity): array
    {
        return [
            'nmId' => $nmId,
            'chrtId' => $chrtId,
            'warehouseId' => $warehouseId,
            'warehouseName' => 'Warehouse '.$warehouseId,
            'quantity' => $quantity,
            'inWayToClient' => 0,
            'inWayFromClient' => 0,
        ];
    }

    /** @param list<array<string, int|string>> $items */
    private function inventoryResponse(array $items): MockResponse
    {
        return new MockResponse(json_encode(['data' => ['items' => $items]], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    /** @param list<array{id: int, name: string}> $warehouses */
    private function sellerWarehousesResponse(array $warehouses): MockResponse
    {
        return new MockResponse(json_encode($warehouses, \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    /** @param list<array{sku: string, amount: int}> $stocks */
    private function sellerStocksResponse(array $stocks): MockResponse
    {
        return new MockResponse(json_encode(['stocks' => $stocks], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    /** @return list<InventoryRawSnapshot> */
    private function rawSnapshots(InventorySnapshotSession $session): array
    {
        return $this->em->getRepository(InventoryRawSnapshot::class)->findBy(['snapshotSessionId' => $session->getId()], ['pageNumber' => 'ASC']);
    }

    private function normalizeMessage(InventorySnapshotSession $session, Company $company): NormalizeInventorySnapshotMessage
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async_pipeline');
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof NormalizeInventorySnapshotMessage && $message->snapshotSessionId === $session->getId()) {
                return $message;
            }
        }

        self::fail('Normalize message not dispatched.');
    }

    private function countNormalizeMessages(InventorySnapshotSession $session, Company $company): int
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async_pipeline');

        return count(array_filter(
            $transport->getSent(),
            static fn ($envelope): bool => ($message = $envelope->getMessage()) instanceof NormalizeInventorySnapshotMessage
                && $message->snapshotSessionId === $session->getId()
                && $message->companyId === $company->getId(),
        ));
    }
}
