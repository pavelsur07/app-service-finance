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
            $this->inventoryResponse([
                $this->stock(100, 1001, 507, 4),
                $this->stock(100, 1002, 507, 7),
            ]),
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

    public function testSeveralPagesUseIncreasingOffsets(): void
    {
        $company = $this->createCompany(702);
        $connection = $this->createConnection($company, 702);
        $session = $this->createSession($company);
        $this->swapHttpClient([
            $this->catalogResponse([$this->card(200, [[2001, 'S', 'barcode-702']])]),
            $this->inventoryResponse([$this->stock(200, 2001, 1, 1), $this->stock(200, 2001, 2, 2)]),
            $this->inventoryResponse([$this->stock(200, 2001, 3, 3)]),
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
            $this->inventoryResponse([$this->stock(800, 8003, 1, 3)]),
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
            $this->inventoryResponse([$this->stock(900, 9001, 1, 1)]),
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
