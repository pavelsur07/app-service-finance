<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Application\Action\SyncStockSnapshotAction;
use App\MoySklad\Application\StockReportPageParser;
use App\MoySklad\Application\StockSnapshotRunner;
use App\MoySklad\Application\StorePageParser;
use App\MoySklad\Application\StoreSyncRunner;
use App\MoySklad\Domain\ProductSnapshot;
use App\MoySklad\Domain\VariantSnapshot;
use App\MoySklad\Entity\MoySkladConnection;
use App\MoySklad\Entity\MoySkladProduct;
use App\MoySklad\Entity\MoySkladStockSnapshot;
use App\MoySklad\Entity\MoySkladSyncRun;
use App\MoySklad\Entity\MoySkladVariant;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Exception\StockSyncException;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladProductRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladStockSnapshotLineRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladStockSnapshotRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladStoreRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncCursorRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladSyncRunRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladVariantRepository;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use App\MoySklad\Message\SyncStockSnapshotMessage;
use App\MoySklad\MessageHandler\SyncStockSnapshotHandler;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SyncStockSnapshotActionTest extends WebTestCaseBase
{
    private const ACCOUNT_ID = '00000000-0000-4000-8000-000000000003';

    public function testLoadsStoresAndPublishesProductAndVariantSnapshotWithoutChangingDecimals(): void
    {
        $this->resetDb();
        $connection = $this->catalog();

        $snapshotId = ($this->action($this->successfulResponses()))($connection->getCompanyId(), $connection->getId());

        self::assertNotNull($snapshotId);
        $db = $this->em()->getConnection();
        self::assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_stores'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_stores WHERE archived'));
        self::assertSame('completed', $db->fetchOne('SELECT status FROM moysklad_stock_snapshots WHERE id = ?', [$snapshotId]));
        self::assertSame(4, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_stock_snapshot_lines WHERE snapshot_id = ?', [$snapshotId]));
        self::assertSame(
            ['-30.0000000000', '0.0000000000'],
            $db->fetchFirstColumn('SELECT stock FROM moysklad_stock_snapshot_lines WHERE snapshot_id = ? ORDER BY stock ASC LIMIT 2', [$snapshotId]),
        );
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_sync_cursors WHERE connection_id = ?', [$connection->getId()]));
        $stockRun = static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'stock');
        self::assertSame(4, $stockRun?->getProcessed());
        self::assertSame(4, $stockRun->getCreated());
        self::assertSame(0, $stockRun->getUpdated());
        self::assertSame(0, $stockRun->getUnchanged());
    }

    public function testRepeatedRunCreatesNewCompletedSnapshotAndUpsertsStores(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $first = ($this->action($this->successfulResponses()))($connection->getCompanyId(), $connection->getId());
        $second = ($this->action($this->successfulResponses()))($connection->getCompanyId(), $connection->getId());

        self::assertNotSame($first, $second);
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM moysklad_stock_snapshots WHERE status = 'completed'"));
        self::assertSame(3, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_stores'));
        $storeRun = static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'store');
        self::assertSame(3, $storeRun?->getUnchanged());
    }

    public function testUnknownStoreFailsOnlyStockAndKeepsSuccessfulStoreCursorAndPreviousSnapshot(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $previous = ($this->action($this->successfulResponses()))($connection->getCompanyId(), $connection->getId());
        $oldStockCursor = $this->em()->getConnection()->fetchOne("SELECT last_completed_at FROM moysklad_sync_cursors WHERE connection_id = ? AND entity_type = 'stock'", [$connection->getId()]);
        $stock = json_decode($this->fixture('Stock/stock_bystore_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $stock['meta']['limit'] = 1000;
        $stock['rows'][0]['stockByStore'][0]['meta']['href'] = 'https://api.moysklad.ru/api/remap/1.2/entity/store/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        try {
            ($this->action([
                new MockResponse($this->storePage('Store/stores_active_page_0.json')),
                new MockResponse($this->storePage('Store/stores_archived_page_0.json')),
                new MockResponse(json_encode($stock, \JSON_THROW_ON_ERROR)),
            ]))($connection->getCompanyId(), $connection->getId());
            self::fail('Unknown store must fail the stock pass.');
        } catch (StockSyncException $error) {
            self::assertSame('temporary', $error->category);
        }

        $db = $this->em()->getConnection();
        self::assertSame($oldStockCursor, $db->fetchOne("SELECT last_completed_at FROM moysklad_sync_cursors WHERE connection_id = ? AND entity_type = 'stock'", [$connection->getId()]));
        self::assertSame($previous, static::getContainer()->get(MoySkladStockSnapshotRepository::class)->latestCompleted($connection->getCompanyId(), $connection->getId())?->getId());
        self::assertSame('succeeded', static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'store')?->getStatus());
        self::assertSame('failed', static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'stock')?->getStatus());
        self::assertNotNull(static::getContainer()->get(MoySkladSyncCursorRepository::class)->findFor($connection->getCompanyId(), $connection->getId(), 'store'));
    }

    public function testRejectsStoreRepeatedAcrossPagesWithoutAdvancingCursor(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $first = $this->generatedStorePage(range(1000, 1099), 101, 0);
        $second = $this->generatedStorePage([1000], 101, 100);
        $emptyStores = '{"meta":{"type":"store","size":0,"limit":100,"offset":0},"rows":[]}';
        $emptyStock = '{"meta":{"type":"stockbystore","size":0,"limit":1000,"offset":0},"rows":[]}';

        try {
            ($this->action([
                new MockResponse($first),
                new MockResponse($second),
                new MockResponse($emptyStores),
                new MockResponse($emptyStock),
            ]))($connection->getCompanyId(), $connection->getId());
            self::fail('Repeated store must fail the store pass.');
        } catch (StockSyncException $error) {
            self::assertSame('temporary', $error->category);
        }

        self::assertNull(static::getContainer()->get(MoySkladSyncCursorRepository::class)->findFor($connection->getCompanyId(), $connection->getId(), 'store'));
        self::assertSame('failed', static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'store')?->getStatus());
    }

    public function testRejectsStockRowMissingAnActiveStore(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $stock = json_decode($this->fixture('Stock/stock_bystore_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $stock['meta']['limit'] = 1000;
        array_pop($stock['rows'][0]['stockByStore']);

        try {
            ($this->action([
                new MockResponse($this->storePage('Store/stores_active_page_0.json')),
                new MockResponse($this->storePage('Store/stores_archived_page_0.json')),
                new MockResponse(json_encode($stock, \JSON_THROW_ON_ERROR)),
            ]))($connection->getCompanyId(), $connection->getId());
            self::fail('Incomplete store coverage must fail the stock pass.');
        } catch (StockSyncException $error) {
            self::assertSame('temporary', $error->category);
        }

        self::assertNull(static::getContainer()->get(MoySkladSyncCursorRepository::class)->findFor($connection->getCompanyId(), $connection->getId(), 'stock'));
    }

    public function testAllowsKnownArchivedStoreInStockReport(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $stock = json_decode($this->fixture('Stock/stock_bystore_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $stock['meta']['limit'] = 1000;
        $archivedLevel = $stock['rows'][0]['stockByStore'][0];
        $archivedLevel['meta']['href'] = 'https://api.moysklad.ru/api/remap/1.2/entity/store/00000000-0000-4000-8000-000000000403';
        $archivedLevel['name'] = 'Archived store';
        foreach ($stock['rows'] as &$row) {
            $row['stockByStore'][] = $archivedLevel;
        }
        unset($row);

        $snapshotId = ($this->action([
            new MockResponse($this->storePage('Store/stores_active_page_0.json')),
            new MockResponse($this->storePage('Store/stores_archived_page_0.json')),
            new MockResponse(json_encode($stock, \JSON_THROW_ON_ERROR)),
        ]))($connection->getCompanyId(), $connection->getId());

        self::assertNotNull($snapshotId);
        self::assertSame(6, static::getContainer()->get(MoySkladStockSnapshotLineRepository::class)->countForSnapshot($connection->getCompanyId(), $snapshotId));
    }

    public function testAllowsStoreToMoveFromActiveToArchivedBetweenPasses(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $active = json_decode($this->storePage('Store/stores_active_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $archived = json_decode($this->storePage('Store/stores_archived_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $moved = $active['rows'][0];
        $moved['archived'] = true;
        $archived['rows'][] = $moved;
        $archived['meta']['size'] = 2;
        $stock = json_decode($this->fixture('Stock/stock_bystore_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $stock['meta']['limit'] = 1000;

        $snapshotId = ($this->action([
            new MockResponse(json_encode($active, \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode($archived, \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode($stock, \JSON_THROW_ON_ERROR)),
        ]))($connection->getCompanyId(), $connection->getId());

        self::assertNotNull($snapshotId);
        self::assertTrue((bool) $this->em()->getConnection()->fetchOne('SELECT archived FROM moysklad_stores WHERE external_id = ?', [$moved['id']]));
    }

    public function testHttpRequestsRunOutsideDatabaseTransactions(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $responses = $this->successfulResponses();
        $index = 0;
        $http = new MockHttpClient(function () use (&$index, $responses): MockResponse {
            self::assertFalse($this->em()->getConnection()->isTransactionActive());

            return $responses[$index++];
        });

        self::assertNotNull(($this->actionWithClient(new MoySkladClient($http, 'https://example.test/api/remap/1.2')))($connection->getCompanyId(), $connection->getId()));
        self::assertSame(3, $index);
    }

    public function testForeignTenantAndHeldAdvisoryLockSkipWithoutHttp(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        self::assertNull(($this->action([]))('99999999-9999-4999-8999-999999999999', $connection->getId()));

        $other = DriverManager::getConnection($this->em()->getConnection()->getParams());
        $params = ['namespace' => 'moysklad-stock-snapshot', 'key' => $connection->getId()];
        $other->fetchOne('SELECT pg_advisory_lock(hashtext(:namespace), hashtext(:key))', $params);
        try {
            self::assertNull(($this->action([]))($connection->getCompanyId(), $connection->getId()));
            self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_sync_runs'));
        } finally {
            $other->fetchOne('SELECT pg_advisory_unlock(hashtext(:namespace), hashtext(:key))', $params);
            $other->close();
        }
    }

    public function testRepairsStaleRunsAndBuildingSnapshotUnderLock(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $staleAt = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $storeRun = new MoySkladSyncRun('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa1', $connection->getCompanyId(), $connection->getId(), 'store', $staleAt);
        $stockRun = new MoySkladSyncRun('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa2', $connection->getCompanyId(), $connection->getId(), 'stock', $staleAt);
        $snapshot = new MoySkladStockSnapshot('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaa3', $connection->getCompanyId(), $connection->getId(), $staleAt);
        foreach ([$storeRun, $stockRun, $snapshot] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        self::assertNotNull(($this->action($this->successfulResponses()))($connection->getCompanyId(), $connection->getId()));
        self::assertSame('failed', $this->em()->getConnection()->fetchOne('SELECT status FROM moysklad_sync_runs WHERE id = ?', [$storeRun->getId()]));
        self::assertSame('internal', $this->em()->getConnection()->fetchOne('SELECT error_category FROM moysklad_sync_runs WHERE id = ?', [$stockRun->getId()]));
        self::assertSame('failed', $this->em()->getConnection()->fetchOne('SELECT status FROM moysklad_stock_snapshots WHERE id = ?', [$snapshot->getId()]));
    }

    public function testEmptyReportPublishesSnapshotWithoutLines(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $empty = '{"meta":{"type":"stockbystore","size":0,"limit":1000,"offset":0},"rows":[]}';

        $snapshotId = ($this->action([
            new MockResponse($this->storePage('Store/stores_active_page_0.json')),
            new MockResponse($this->storePage('Store/stores_archived_page_0.json')),
            new MockResponse($empty),
        ]))($connection->getCompanyId(), $connection->getId());

        self::assertNotNull($snapshotId);
        self::assertSame('completed', $this->em()->getConnection()->fetchOne('SELECT status FROM moysklad_stock_snapshots WHERE id = ?', [$snapshotId]));
        self::assertSame(0, static::getContainer()->get(MoySkladStockSnapshotLineRepository::class)->countForSnapshot($connection->getCompanyId(), $snapshotId));
    }

    public function testMissingStoreIsRetainedAndArchiveChangesOnlyOnExplicitRow(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        ($this->action($this->successfulResponses()))($connection->getCompanyId(), $connection->getId());
        $active = json_decode($this->storePage('Store/stores_active_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $archived = json_decode($this->storePage('Store/stores_archived_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $explicitlyArchivedId = $active['rows'][0]['id'];
        $missingId = $active['rows'][1]['id'];
        $active['rows'][0]['archived'] = true;
        $archived['rows'][] = $active['rows'][0];
        $archived['meta']['size'] = 2;
        $emptyActive = '{"meta":{"type":"store","size":0,"limit":100,"offset":0},"rows":[]}';
        $stock = '{"meta":{"type":"stockbystore","size":0,"limit":1000,"offset":0},"rows":[]}';

        ($this->action([
            new MockResponse($emptyActive),
            new MockResponse(json_encode($archived, \JSON_THROW_ON_ERROR)),
            new MockResponse($stock),
        ]))($connection->getCompanyId(), $connection->getId());

        $db = $this->em()->getConnection();
        self::assertTrue((bool) $db->fetchOne('SELECT archived FROM moysklad_stores WHERE external_id = ?', [$explicitlyArchivedId]));
        self::assertFalse((bool) $db->fetchOne('SELECT archived FROM moysklad_stores WHERE external_id = ?', [$missingId]));
        self::assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_stores'));
    }

    #[DataProvider('unknownAssortments')]
    public function testUnknownAssortmentIsTemporary(string $type): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $stock = json_decode($this->fixture('Stock/stock_bystore_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $stock['meta']['limit'] = 1000;
        $stock['rows'][0]['meta']['type'] = $type;
        $stock['rows'][0]['meta']['href'] = sprintf('https://api.moysklad.ru/api/remap/1.2/entity/%s/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $type);

        try {
            ($this->action([
                new MockResponse($this->storePage('Store/stores_active_page_0.json')),
                new MockResponse($this->storePage('Store/stores_archived_page_0.json')),
                new MockResponse(json_encode($stock, \JSON_THROW_ON_ERROR)),
            ]))($connection->getCompanyId(), $connection->getId());
            self::fail('Unknown assortment must fail.');
        } catch (StockSyncException $error) {
            self::assertSame('temporary', $error->category);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unknownAssortments(): iterable
    {
        yield 'product' => ['product'];
        yield 'variant' => ['variant'];
    }

    public function testPaginatesStableReportAndSuccessfulRetryRestartsAtZeroAfterSecondPageFailure(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $ids = $this->addProducts($connection, 1001);
        $firstPage = new MockResponse($this->stockPage(array_slice($ids, 0, 1000), 1001, 0));
        $secondPage = new MockResponse($this->stockPage(array_slice($ids, 1000), 1001, 1000));

        $completed = ($this->action([
            new MockResponse($this->storePage('Store/stores_active_page_0.json')),
            new MockResponse($this->storePage('Store/stores_archived_page_0.json')),
            $firstPage,
            $secondPage,
        ]))($connection->getCompanyId(), $connection->getId());
        self::assertNotNull($completed);
        self::assertStringContainsString('offset=0', $firstPage->getRequestUrl());
        self::assertStringContainsString('offset=1000', $secondPage->getRequestUrl());

        $restartFirstPage = new MockResponse($this->stockPage(array_slice($ids, 0, 1000), 1001, 0));
        try {
            ($this->action([
                new MockResponse($this->storePage('Store/stores_active_page_0.json')),
                new MockResponse($this->storePage('Store/stores_archived_page_0.json')),
                $restartFirstPage,
                new MockResponse('', ['http_code' => 503]),
            ]))($connection->getCompanyId(), $connection->getId());
            self::fail('Second page failure expected.');
        } catch (StockSyncException $error) {
            self::assertSame('temporary', $error->category);
        }
        self::assertStringContainsString('offset=0', $restartFirstPage->getRequestUrl());
        self::assertSame($completed, static::getContainer()->get(MoySkladStockSnapshotRepository::class)->latestCompleted($connection->getCompanyId(), $connection->getId())?->getId());

        $db = $this->em()->getConnection();
        $failedSnapshotId = $db->fetchOne("SELECT id FROM moysklad_stock_snapshots WHERE status = 'failed' ORDER BY started_at DESC LIMIT 1");
        self::assertIsString($failedSnapshotId);
        self::assertSame(2000, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_stock_snapshot_lines WHERE snapshot_id = ?', [$failedSnapshotId]));

        $retryFirstPage = new MockResponse($this->stockPage(array_slice($ids, 0, 1000), 1001, 0));
        $retried = ($this->action([
            new MockResponse($this->storePage('Store/stores_active_page_0.json')),
            new MockResponse($this->storePage('Store/stores_archived_page_0.json')),
            $retryFirstPage,
            new MockResponse($this->stockPage(array_slice($ids, 1000), 1001, 1000)),
        ]))($connection->getCompanyId(), $connection->getId());

        self::assertNotSame($completed, $retried);
        self::assertStringContainsString('offset=0', $retryFirstPage->getRequestUrl());
        self::assertSame($retried, static::getContainer()->get(MoySkladStockSnapshotRepository::class)->latestCompleted($connection->getCompanyId(), $connection->getId())?->getId());
        self::assertSame(2000, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_stock_snapshot_lines WHERE snapshot_id = ?', [$failedSnapshotId]));
    }

    #[DataProvider('unstableSecondPages')]
    public function testRejectsUnstableSecondPage(string $mode, string $category): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $ids = $this->addProducts($connection, 1002);
        $second = match ($mode) {
            'size' => $this->stockPage(array_slice($ids, 1000, 2), 1002, 1000),
            'duplicate' => $this->stockPage([$ids[0]], 1001, 1000),
            'empty' => '{"meta":{"type":"stockbystore","size":1001,"limit":1000,"offset":1000},"rows":[]}',
            default => throw new \LogicException('Unknown unstable page mode.'),
        };

        try {
            ($this->action([
                new MockResponse($this->storePage('Store/stores_active_page_0.json')),
                new MockResponse($this->storePage('Store/stores_archived_page_0.json')),
                new MockResponse($this->stockPage(array_slice($ids, 0, 1000), 1001, 0)),
                new MockResponse($second),
            ]))($connection->getCompanyId(), $connection->getId());
            self::fail('Unstable report must fail.');
        } catch (StockSyncException $error) {
            self::assertSame($category, $error->category);
        }
        self::assertNull(static::getContainer()->get(MoySkladSyncCursorRepository::class)->findFor($connection->getCompanyId(), $connection->getId(), 'stock'));
        self::assertSame('failed', static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'stock')?->getStatus());
    }

    /** @return iterable<string, array{string, string}> */
    public static function unstableSecondPages(): iterable
    {
        yield 'changed size' => ['size', 'temporary'];
        yield 'duplicate assortment' => ['duplicate', 'temporary'];
        yield 'premature empty page' => ['empty', 'invalid_response'];
    }

    public function testClosedEntityManagerStillRecordsSafeStoreFailure(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $db = $this->em()->getConnection();
        $db->executeStatement("CREATE FUNCTION test_fail_moysklad_store_insert() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''forced store failure''; END'");
        $db->executeStatement('CREATE TRIGGER test_fail_moysklad_store_insert BEFORE INSERT ON moysklad_stores FOR EACH ROW EXECUTE FUNCTION test_fail_moysklad_store_insert()');

        try {
            ($this->action([new MockResponse($this->storePage('Store/stores_active_page_0.json'))]))($connection->getCompanyId(), $connection->getId());
            self::fail('Database failure must close the EntityManager.');
        } catch (StockSyncException $error) {
            self::assertSame('internal', $error->category);
        } finally {
            $db->executeStatement('DROP TRIGGER test_fail_moysklad_store_insert ON moysklad_stores');
            $db->executeStatement('DROP FUNCTION test_fail_moysklad_store_insert()');
        }

        $row = $db->fetchAssociative("SELECT status, error_category FROM moysklad_sync_runs WHERE connection_id = ? AND entity_type = 'store'", [$connection->getId()]);
        self::assertSame(['status' => 'failed', 'error_category' => 'internal'], $row);
    }

    public function testUnexpectedActionFailureIsNormalizedAsPermanentInternalError(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $action = $this->action([]);
        $this->em()->close();

        try {
            $action($connection->getCompanyId(), $connection->getId());
            self::fail('Closed EntityManager must fail.');
        } catch (StockSyncException $error) {
            self::assertSame('internal', $error->category);
            self::assertNull($error->retryAfterMs);
        }
    }

    public function testHandlerUsesRateLimitDelayAndIncrementsAttempt(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $dispatched = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (SyncStockSnapshotMessage $message, array $stamps) use (&$dispatched): Envelope {
            $dispatched = [$message, $stamps];

            return new Envelope($message);
        });
        $handler = new SyncStockSnapshotHandler(
            $this->action([new MockResponse('', ['http_code' => 429, 'response_headers' => ['X-Lognex-Retry-After: 60000']])]),
            $bus,
            new NullLogger(),
        );

        $handler(new SyncStockSnapshotMessage($connection->getCompanyId(), $connection->getId()));

        self::assertNotNull($dispatched);
        self::assertSame(1, $dispatched[0]->attempt);
        self::assertInstanceOf(DelayStamp::class, $dispatched[1][0]);
        self::assertSame(60_000, $dispatched[1][0]->getDelay());
    }

    public function testHandlerUsesExponentialDelayForTemporaryFailure(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $delay = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (SyncStockSnapshotMessage $message, array $stamps) use (&$delay): Envelope {
            $delay = $stamps[0]->getDelay();

            return new Envelope($message);
        });
        $handler = new SyncStockSnapshotHandler($this->action([new MockResponse('', ['http_code' => 503])]), $bus, new NullLogger());

        $handler(new SyncStockSnapshotMessage($connection->getCompanyId(), $connection->getId(), 2));

        self::assertSame(40_000, $delay);
    }

    #[DataProvider('permanentFailures')]
    public function testHandlerDoesNotRetryPermanentFailure(int $status, string $body): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $handler = new SyncStockSnapshotHandler($this->action([new MockResponse($body, ['http_code' => $status])]), $bus, new NullLogger());

        $handler(new SyncStockSnapshotMessage($connection->getCompanyId(), $connection->getId()));

        self::assertSame('failed', static::getContainer()->get(MoySkladSyncRunRepository::class)->latestFor($connection->getCompanyId(), $connection->getId(), 'store')?->getStatus());
    }

    /** @return iterable<string, array{int, string}> */
    public static function permanentFailures(): iterable
    {
        yield 'unauthorized' => [401, ''];
        yield 'forbidden' => [403, ''];
        yield 'invalid request' => [400, ''];
        yield 'invalid response' => [200, '{'];
    }

    public function testHandlerDoesNotRetryInternalFailure(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $connection->setAccessTokenEncrypted('invalid-encrypted-payload');
        $this->em()->flush();
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        (new SyncStockSnapshotHandler($this->action([]), $bus, new NullLogger()))(
            new SyncStockSnapshotMessage($connection->getCompanyId(), $connection->getId()),
        );

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_sync_runs'));
    }

    public function testHandlerStopsAfterFourthAttempt(): void
    {
        $this->resetDb();
        $connection = $this->catalog();
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('MoySklad stock snapshot sync retry budget exhausted', self::callback(static function (array $context): bool {
            self::assertSame('rate_limited', $context['category']);
            self::assertSame(3, $context['attempt']);
            self::assertStringNotContainsString('private-', serialize($context));

            return true;
        }));
        $handler = new SyncStockSnapshotHandler($this->action([new MockResponse('private-response', ['http_code' => 429])]), $bus, $logger);

        $handler(new SyncStockSnapshotMessage($connection->getCompanyId(), $connection->getId(), 3));
    }

    public function testMessageRoutesToAsyncSync(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async_sync');
        $transport->reset();
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new SyncStockSnapshotMessage(
            '11111111-1111-7111-8111-111111111111',
            '33333333-3333-7333-8333-333333333333',
        ));

        self::assertCount(1, $transport->getSent());
        self::assertInstanceOf(SyncStockSnapshotMessage::class, $transport->getSent()[0]->getMessage());
    }

    private function catalog(): MoySkladConnection
    {
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $connection->bindAccount(self::ACCOUNT_ID);
        $connection->setAccessToken('test-secret');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $now = new \DateTimeImmutable('2026-09-21T08:00:00+00:00');
        $product = new MoySkladProduct('11111111-1111-7111-8111-111111111112', $connection->getCompanyId(), $connection->getId(), new ProductSnapshot('00000000-0000-4000-8000-000000000001', 'Product', 'product-external', null, null, 1, false, $now), $now);
        $variant = new MoySkladVariant('11111111-1111-7111-8111-111111111113', $connection->getCompanyId(), $connection->getId(), new VariantSnapshot('00000000-0000-4000-8000-000000000101', $product->getExternalId(), 'Variant', 'variant-external', null, null, [], false, $now), $now);
        foreach ([$connection, $product, $variant] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        return $connection;
    }

    /** @param list<MockResponse> $responses */
    private function action(array $responses): SyncStockSnapshotAction
    {
        return $this->actionWithClient(new MoySkladClient(new MockHttpClient($responses), 'https://example.test/api/remap/1.2'));
    }

    private function actionWithClient(MoySkladClient $client): SyncStockSnapshotAction
    {
        $stores = new StoreSyncRunner(
            $this->em(),
            static::getContainer()->get(MoySkladStoreRepository::class),
            static::getContainer()->get(MoySkladSyncCursorRepository::class),
            static::getContainer()->get(MoySkladSyncRunRepository::class),
            $client,
            new StorePageParser(),
        );
        $stock = new StockSnapshotRunner(
            $this->em(),
            static::getContainer()->get(MoySkladStoreRepository::class),
            static::getContainer()->get(MoySkladProductRepository::class),
            static::getContainer()->get(MoySkladVariantRepository::class),
            static::getContainer()->get(MoySkladStockSnapshotRepository::class),
            static::getContainer()->get(MoySkladStockSnapshotLineRepository::class),
            static::getContainer()->get(MoySkladSyncCursorRepository::class),
            static::getContainer()->get(MoySkladSyncRunRepository::class),
            $client,
            new StockReportPageParser(),
        );

        return new SyncStockSnapshotAction(
            $this->em(),
            static::getContainer()->get(MoySkladConnectionWriteRepository::class),
            $stores,
            $stock,
            static::getContainer()->get(ConnectionTokenCodec::class),
            new NullLogger(),
        );
    }

    /** @return list<MockResponse> */
    private function successfulResponses(): array
    {
        $stock = json_decode($this->fixture('Stock/stock_bystore_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $stock['meta']['limit'] = 1000;

        return [
            new MockResponse($this->storePage('Store/stores_active_page_0.json')),
            new MockResponse($this->storePage('Store/stores_archived_page_0.json')),
            new MockResponse(json_encode($stock, \JSON_THROW_ON_ERROR)),
        ];
    }

    private function storePage(string $name): string
    {
        $page = json_decode($this->fixture($name), true, 512, \JSON_THROW_ON_ERROR);
        $page['meta']['limit'] = 100;

        return json_encode($page, \JSON_THROW_ON_ERROR);
    }

    /** @param list<int> $suffixes */
    private function generatedStorePage(array $suffixes, int $size, int $offset): string
    {
        $fixture = json_decode($this->fixture('Store/stores_active_page_0.json'), true, 512, \JSON_THROW_ON_ERROR);
        $template = $fixture['rows'][0];
        $rows = [];
        foreach ($suffixes as $suffix) {
            $id = sprintf('00000000-0000-4000-8000-%012d', $suffix);
            $row = $template;
            $row['id'] = $id;
            $row['accountId'] = self::ACCOUNT_ID;
            $row['meta']['href'] = 'https://api.moysklad.ru/api/remap/1.2/entity/store/'.$id;
            $row['name'] = 'Store '.$suffix;
            $row['externalCode'] = 'store-'.$suffix;
            $rows[] = $row;
        }

        return json_encode([
            'meta' => ['type' => 'store', 'size' => $size, 'limit' => 100, 'offset' => $offset],
            'rows' => $rows,
        ], \JSON_THROW_ON_ERROR);
    }

    private function fixture(string $name): string
    {
        $body = file_get_contents(__DIR__.'/../../Fixtures/MoySklad/'.$name);
        self::assertIsString($body);

        return $body;
    }

    /** @return list<string> */
    private function addProducts(MoySkladConnection $connection, int $count): array
    {
        $now = new \DateTimeImmutable('2026-09-21T08:00:00+00:00');
        $ids = [];
        for ($index = 1000; $index < 1000 + $count; ++$index) {
            $externalId = sprintf('00000000-0000-4000-8000-%012d', $index);
            $ids[] = $externalId;
            $this->em()->persist(new MoySkladProduct(
                sprintf('11111111-1111-7111-8111-%012d', $index),
                $connection->getCompanyId(),
                $connection->getId(),
                new ProductSnapshot($externalId, 'Product '.$index, 'external-'.$index, null, null, 0, false, $now),
                $now,
            ));
        }
        $this->em()->flush();

        return $ids;
    }

    /** @param list<string> $externalIds */
    private function stockPage(array $externalIds, int $size, int $offset): string
    {
        $levels = [
            [
                'meta' => ['href' => 'https://api.moysklad.ru/api/remap/1.2/entity/store/00000000-0000-4000-8000-000000000401', 'type' => 'store'],
                'name' => 'Store 401',
                'stock' => 0,
                'reserve' => 0,
                'inTransit' => 0,
            ],
            [
                'meta' => ['href' => 'https://api.moysklad.ru/api/remap/1.2/entity/store/00000000-0000-4000-8000-000000000402', 'type' => 'store'],
                'name' => 'Store 402',
                'stock' => 0,
                'reserve' => 0,
                'inTransit' => 0,
            ],
        ];
        $rows = array_map(static fn (string $externalId): array => [
            'meta' => [
                'href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/'.$externalId,
                'type' => 'product',
            ],
            'stockByStore' => $levels,
        ], $externalIds);

        return json_encode([
            'meta' => ['type' => 'stockbystore', 'size' => $size, 'limit' => 1000, 'offset' => $offset],
            'rows' => $rows,
        ], \JSON_THROW_ON_ERROR);
    }
}
