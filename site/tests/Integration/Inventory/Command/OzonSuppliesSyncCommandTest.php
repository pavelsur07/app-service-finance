<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Command;

use App\Company\Entity\Company;
use App\Inventory\Enum\StockSnapshotMappingStatus;
use App\Inventory\Enum\StockStatus;
use App\Inventory\Facade\InventoryFacade;
use App\Inventory\Infrastructure\Api\OzonFetcher\OzonSupplySnapshotClient;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Inventory\StockSnapshotBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Поставки Ozon в пути: снимок ozon-fetcher → строки in_supply в сессии дневного снимка остатков.
 */
final class OzonSuppliesSyncCommandTest extends IntegrationTestCase
{
    private const CONNECTION_ID = '77777777-7777-7777-7777-000000000951';
    private const STOCK_SESSION = '22222222-2222-2222-2222-000000000951';
    private const LISTING_ID = '55555555-5555-5555-5555-000000000951';

    private Company $company;
    private string $today;
    private MockResponse $response;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $user = UserBuilder::aUser()->withIndex(951)->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(951)->withOwner($user)->build();
        $connection = new MarketplaceConnection(self::CONNECTION_ID, $this->company, MarketplaceType::OZON, MarketplaceConnectionType::SELLER);
        $connection->setApiKey('test-key')->setClientId('test-client')->setIsActive(true);
        $listing = MarketplaceListingBuilder::aListing()->withId(self::LISTING_ID)->forCompany($this->company)
            ->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('111')->build();
        foreach ([$user, $this->company, $connection, $listing] as $entity) {
            $this->em->persist($entity);
        }
        // Дневной снимок остатков 04:05: SKU 111 — 3 шт на складе.
        $this->em->persist(StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId((string) $this->company->getId())
            ->withSnapshotSessionId(self::STOCK_SESSION)
            ->withSnapshotDate(new \DateTimeImmutable($this->today))
            ->withSnapshotAt(new \DateTimeImmutable($this->today.' 04:05:00'))
            ->withSource(MarketplaceType::OZON)
            ->withListingId(self::LISTING_ID)
            ->withProductId(null)
            ->withMappingStatus(StockSnapshotMappingStatus::Mapped)
            ->withSourceSku('111')
            ->withFulfillmentType('fbo')
            ->withStatus(StockStatus::Available)
            ->withQuantity('3')
            ->build());
        $this->em->flush();

        // Клиент подменяется один раз: ответ берётся из $this->response на момент запроса.
        self::getContainer()->set(OzonSupplySnapshotClient::class, new OzonSupplySnapshotClient(
            new MockHttpClient(fn (): MockResponse => $this->response),
            new NullLogger(),
            'http://ozon-fetcher:8080',
            'test-token',
        ));
    }

    public function testSuppliesAreWrittenIntoStockSessionWithoutChangingAvailableStock(): void
    {
        $before = $this->stockOnDate();
        $this->useSnapshot($this->snapshotBody($this->today));

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('позиций 4, строк 3, штук 512.000', $tester->getDisplay());
        self::assertSame([
            ['source_sku' => '111', 'quantity' => '500.000', 'name' => 'Поставка: Тверь', 'mapping_status' => 'mapped', 'snapshot_session_id' => self::STOCK_SESSION],
            ['source_sku' => '111', 'quantity' => '5.000', 'name' => 'Ozon — поставки', 'mapping_status' => 'mapped', 'snapshot_session_id' => self::STOCK_SESSION],
            ['source_sku' => '222', 'quantity' => '7.000', 'name' => 'Поставка: Тверь', 'mapping_status' => 'unmapped', 'snapshot_session_id' => self::STOCK_SESSION],
        ], $this->supplyRows());
        self::assertSame($before, $this->stockOnDate(), 'остаток для юнит-экономики и оборачиваемости не меняется');
        self::assertSame(1, (int) $this->connection()->fetchOne(
            "SELECT count(*) FROM inventory_raw_snapshots WHERE company_id = ? AND source_endpoint LIKE 'ozon-fetcher %'",
            [$this->company->getId()],
        ));
    }

    public function testRepeatReplacesTheDayAndDropsArrivedSupplies(): void
    {
        $this->useSnapshot($this->snapshotBody($this->today));
        $this->runCommand();
        $this->useSnapshot($this->snapshotBody($this->today, arrived: true));

        $this->runCommand();

        self::assertSame(['111'], array_column($this->supplyRows(), 'source_sku'), 'поставка Твери приехала — строк по ней нет');
    }

    public function testSkipsWithoutStockSessionStaleSnapshotOrMissingSnapshot(): void
    {
        $this->useSnapshot($this->snapshotBody((new \DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d')));
        self::assertStringContainsString('пропуск (no_stock_session)', $this->runCommand()->getDisplay());

        $this->useSnapshot($this->snapshotBody((new \DateTimeImmutable('today'))->modify('-3 days')->format('Y-m-d')));
        self::assertStringContainsString('пропуск (stale)', $this->runCommand()->getDisplay());

        $this->useSnapshot(null);
        $tester = $this->runCommand();
        self::assertStringContainsString('пропуск (no_snapshot)', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([], $this->supplyRows());
    }

    public function testBrokenSnapshotFailsCommandAndWritesNothing(): void
    {
        $body = $this->snapshotBody($this->today);
        array_pop($body['pages']);
        $this->useSnapshot($body);

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('ошибка UnexpectedValueException', $tester->getDisplay());
        self::assertSame([], $this->supplyRows());
    }

    /**
     * Заявка 11 (в пути): поставка b-1 без склада — 5 шт SKU 111; поставка b-2 на Тверь — 300 + 200 шт
     * SKU 111 (две страницы состава) и 7 шт SKU 222. Заявка 12 — черновик, не считается.
     *
     * @return array<string, mixed>
     */
    private function snapshotBody(string $day, bool $arrived = false): array
    {
        $supplies = [['bundle_id' => 'b-1']];
        $pages = [['items' => [['sku' => 111, 'offer_id' => 'A', 'quantity' => 5]], 'has_next' => false]];
        if (!$arrived) {
            $supplies[] = ['bundle_id' => 'b-2', 'storage_warehouse' => ['warehouse_id' => 900, 'name' => 'Тверь']];
            $pages[] = ['items' => [['sku' => 111, 'offer_id' => 'A', 'quantity' => 300], ['sku' => 222, 'offer_id' => 'B', 'quantity' => 7]], 'has_next' => true, 'last_id' => 'n'];
            $pages[] = ['items' => [['sku' => 111, 'offer_id' => 'A', 'quantity' => 200]], 'has_next' => false];
        }
        $pages[] = ['items' => [['sku' => 999, 'quantity' => 1000]], 'has_next' => false];

        return [
            'schema_version' => '1',
            'service' => 'ozon-fetcher',
            'connection_ref' => self::CONNECTION_ID,
            'resource_type' => 'ozon.supplies',
            'period_key' => $day,
            'loaded_at' => $day.'T02:05:00Z',
            'pages' => [
                ['order_ids' => [11, 12], 'last_id' => ''],
                ['orders' => [
                    ['order_id' => 11, 'state' => 'IN_TRANSIT', 'supplies' => $supplies],
                    ['order_id' => 12, 'state' => 'DATA_FILLING', 'supplies' => [['bundle_id' => 'z-9']]],
                ]],
                ...$pages,
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $body null — 404 snapshot_not_found
     */
    private function useSnapshot(?array $body): void
    {
        $this->response = null === $body
            ? new MockResponse('{"error":{"code":"snapshot_not_found"}}', ['http_code' => 404])
            : new MockResponse((string) json_encode($body), ['http_code' => 200]);
    }

    private function runCommand(): CommandTester
    {
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:inventory:ozon-supplies-sync'));
        $tester->execute(['--company-id' => $this->company->getId()]);

        return $tester;
    }

    /** @return list<array<string, mixed>> */
    private function supplyRows(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT s.source_sku, s.quantity::text AS quantity, l.name, s.mapping_status, s.snapshot_session_id::text AS snapshot_session_id
             FROM inventory_stock_snapshots s JOIN inventory_locations l ON l.id = s.location_id
             WHERE s.company_id = ? AND s.status = ? ORDER BY s.source_sku, s.quantity DESC',
            [$this->company->getId(), StockStatus::InSupply->value],
        );
    }

    /** @return array<string, float> */
    private function stockOnDate(): array
    {
        return self::getContainer()->get(InventoryFacade::class)
            ->getStockQtyByListingOnReportDate((string) $this->company->getId(), new \DateTimeImmutable($this->today))
            ->qtyByListingId;
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
