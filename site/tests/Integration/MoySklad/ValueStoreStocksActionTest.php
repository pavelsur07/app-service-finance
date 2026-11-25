<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\Balance\Application\BalanceLedgerService;
use App\Balance\Facade\BalanceFacade;
use App\Balance\Security\BalanceAccess;
use App\Company\Facade\CompanyFacade;
use App\MoySklad\Application\Action\ValueStoreStocksAction;
use App\MoySklad\Application\StockReportPageParser;
use App\MoySklad\Command\StoreBalanceDailyCommand;
use App\MoySklad\Command\StoreBalanceRequeueCommand;
use App\MoySklad\Entity\MoySkladStockSnapshot;
use App\MoySklad\Entity\MoySkladStockSnapshotLine;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Exception\StockSyncException;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use App\MoySklad\Message\SyncStockSnapshotMessage;
use App\MoySklad\Message\ValueStoreStocksMessage;
use App\Shared\Service\ActiveCompanyService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Builders\MoySklad\MoySkladProductBuilder;
use App\Tests\Builders\MoySklad\MoySkladStoreBuilder;
use App\Tests\Builders\MoySklad\MoySkladVariantBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** Оценка складов по себестоимости и установка остатка счёта Баланса. */
final class ValueStoreStocksActionTest extends IntegrationTestCase
{
    private const COMPANY = MoySkladConnectionBuilder::DEFAULT_COMPANY_ID;
    private const CONNECTION = MoySkladConnectionBuilder::DEFAULT_ID;
    private const SNAPSHOT = '0193f000-0000-7000-8000-000000000001';
    private const SNAPSHOT_2 = '0193f000-0000-7000-8000-000000000002';
    private const SNAPSHOT_3 = '0193f000-0000-7000-8000-000000000003';
    private string $owner;
    private string $product;
    private string $variant;
    private string $storeA;
    private string $storeB;
    /** @var array<string, string> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $user = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY)->withOwner($user)->build();
        $connection = MoySkladConnectionBuilder::aConnection()->build();
        $connection->bindAccount('00000000-0000-4000-8000-000000000003');
        $connection->setAccessToken('test-secret');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $product = MoySkladProductBuilder::aProduct()->forConnection($connection)->build();
        $variant = MoySkladVariantBuilder::aVariant()->forProduct($product)->build();
        $storeA = MoySkladStoreBuilder::aStore()->withIndex(1)->build();
        $storeB = MoySkladStoreBuilder::aStore()->withIndex(2)->build();
        foreach ([$user, $company, $connection, $product, $variant, $storeA, $storeB] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->owner = (string) $user->getId();
        $this->product = $product->getExternalId();
        $this->variant = $variant->getExternalId();
        $this->storeA = $storeA->getExternalId();
        $this->storeB = $storeB->getExternalId();
        $this->snapshot(self::SNAPSHOT, '2025-01-05T21:30:00Z', [[$this->storeA, 'product', $this->product, '2'], [$this->storeA, 'variant', $this->variant, '3'], [$this->storeB, 'product', $this->product, '1.5']]);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $active = $this->createMock(ActiveCompanyService::class);
        $active->method('getActiveCompany')->willReturn($company);
        $ledger = new BalanceLedgerService($this->connection, new BalanceAccess($security, self::getContainer()->get(CompanyFacade::class), $this->connection, $active));
        $ledger->configureBook(self::COMPANY, 'RUB', '2025-01-01', $this->owner);
        foreach (['money' => 'asset', 'stock' => 'asset', 'equity' => 'passive'] as $name => $type) {
            $articleId = Uuid::uuid7()->toString();
            $this->accounts[$name] = Uuid::uuid7()->toString();
            $this->connection->insert('balance_articles', ['id' => $articleId, 'company_id' => self::COMPANY, 'name' => $name, 'type' => $type, 'kind' => 'article', 'level' => 1, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
            $this->connection->insert('balance_accounts', ['id' => $this->accounts[$name], 'company_id' => self::COMPANY, 'article_id' => $articleId, 'code' => $name, 'name' => $name, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
        }
        $opening = $ledger->saveDraft(self::COMPANY, $this->owner, Uuid::uuid7()->toString(), 'opening', '2025-01-01', 'Основание', [
            ['accountId' => $this->accounts['money'], 'direction' => 'increase', 'amount' => '1000'],
            ['accountId' => $this->accounts['equity'], 'direction' => 'increase', 'amount' => '1000'],
        ]);
        $ledger->post(self::COMPANY, $this->owner, $opening, 1, true);
        foreach ([$this->storeA, $this->storeB] as $store) {
            $this->connection->insert('moysklad_store_balance_links', ['company_id' => self::COMPANY, 'connection_id' => self::CONNECTION, 'store_external_id' => $store,
                'balance_account_id' => $this->accounts['stock'], 'excluded' => 'false', 'actor_user_id' => $this->owner, 'updated_at' => '2025-01-01 00:00:00']);
        }
    }

    public function testValuesStoresPostsTargetAndReplayKeepsSameRows(): void
    {
        $this->enable();
        self::assertSame('posted', ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT));

        // A: 2 × 154,333333 ₽ = 308,67 ₽ (вариант без себестоимости); B: 1,5 × 154,333333 ₽ = 231,50 ₽.
        self::assertSame([
            ['store_external_id' => $this->storeA, 'valuation_date' => '2025-01-06', 'amount_minor' => 30867, 'unpriced_positions' => 1],
            ['store_external_id' => $this->storeB, 'valuation_date' => '2025-01-06', 'amount_minor' => 23150, 'unpriced_positions' => 0],
        ], $this->valuations());
        self::assertSame('54017', $this->balance('stock'));
        $rows = $this->counts();

        self::assertSame('posted', ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT));
        self::assertSame($rows, $this->counts());
        self::assertSame('54017', $this->balance('stock'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT attempts FROM moysklad_store_balance_runs WHERE company_id = ? AND snapshot_id = ?', [self::COMPANY, self::SNAPSHOT]));
    }

    public function testReplayDoesNotRevalueWithNewCost(): void
    {
        $this->enable();
        ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT);
        $rows = $this->counts();

        // Повтор того же снимка после смены себестоимости в МойСклад: HTTP не нужен, суммы прежние.
        self::assertSame('posted', ($this->action([]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT));
        self::assertSame($rows, $this->counts());
        self::assertSame('54017', $this->balance('stock'));
    }

    public function testSameDaySequenceReturnsToEarlierTarget(): void
    {
        $this->enable();
        ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT);
        $this->snapshot(self::SNAPSHOT_2, '2025-01-05T21:40:00Z', [[$this->storeA, 'product', $this->product, '4']]);
        ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT_2);
        self::assertSame('61733', $this->balance('stock'));
        $this->snapshot(self::SNAPSHOT_3, '2025-01-05T21:50:00Z', [[$this->storeA, 'product', $this->product, '2'], [$this->storeB, 'product', $this->product, '1.5']]);

        self::assertSame('posted', ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT_3));
        self::assertSame('54017', $this->balance('stock'));
    }

    public function testOlderSnapshotDoesNotOverrideNewer(): void
    {
        $this->enable();
        $this->snapshot(self::SNAPSHOT_2, '2025-01-05T21:40:00Z', [[$this->storeA, 'product', $this->product, '4']]);
        ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT_2);
        $operations = $this->counts()['operations'];

        self::assertSame('skipped', ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT));
        self::assertSame('superseded', $this->connection->fetchOne('SELECT error_reason FROM moysklad_store_balance_runs WHERE company_id = ? AND snapshot_id = ?', [self::COMPANY, self::SNAPSHOT]));
        self::assertSame($operations, $this->counts()['operations']);
        self::assertSame('61733', $this->balance('stock'));
    }

    public function testDailyAndRequeueCommandsPickEnabledAndInterruptedRuns(): void
    {
        $this->enable();
        $sent = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$sent): Envelope {
            $sent[] = $message;

            return new Envelope($message);
        });
        (new CommandTester(new StoreBalanceDailyCommand($this->connection, $bus)))->execute([]);
        self::assertEquals([new SyncStockSnapshotMessage(self::COMPANY, self::CONNECTION)], $sent);

        // Воркер упал между оценкой и проводкой: прогон остался в posting без finished_at.
        $this->connection->insert('moysklad_store_balance_runs', ['company_id' => self::COMPANY, 'connection_id' => self::CONNECTION, 'snapshot_id' => self::SNAPSHOT,
            'valuation_date' => '2025-01-06', 'status' => 'posting', 'attempts' => 1, 'created_at' => '2025-01-05 21:40:00']);
        $sent = [];
        (new CommandTester(new StoreBalanceRequeueCommand($this->connection, $bus, new MockClock('2025-01-06 10:00:00', 'UTC'))))->execute([]);
        self::assertEquals([new ValueStoreStocksMessage(self::COMPANY, self::CONNECTION, self::SNAPSHOT)], $sent);
    }

    public function testShiftedCostReportIsRetriedNotValued(): void
    {
        $this->enable();
        $page = fn (int $offset): MockResponse => new MockResponse('{"meta":{"size":2,"limit":1,"offset":'.$offset.'},"rows":['
            .'{"meta":{"type":"product","href":"https://api.moysklad.ru/api/remap/1.2/entity/product/'.$this->product.'"},"price":100}]}');
        try {
            ($this->action([$page(0), $page(1)]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT);
            self::fail('Duplicate row across pages must fail the pass.');
        } catch (StockSyncException $error) {
            self::assertSame('temporary', $error->category);
        }
        self::assertSame([], $this->valuations());
        self::assertSame(1, $this->counts()['operations']);
    }

    public function testOlderSnapshotStaysSupersededAfterDisableReplayAndEnable(): void
    {
        $this->enable();
        $this->snapshot(self::SNAPSHOT_2, '2025-01-05T21:40:00Z', [[$this->storeA, 'product', $this->product, '4']]);
        ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT_2);
        $this->connection->update('moysklad_store_balance_settings', ['enabled' => 'false'], ['company_id' => self::COMPANY]);
        self::assertSame('valued', ($this->action([]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT_2));
        $this->connection->update('moysklad_store_balance_settings', ['enabled' => 'true'], ['company_id' => self::COMPANY]);

        self::assertSame('skipped', ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT));
        self::assertSame('61733', $this->balance('stock'));
    }

    public function testMissingCostRowBlocksAccountInsteadOfZeroing(): void
    {
        $this->enable();
        ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT);
        $this->snapshot(self::SNAPSHOT_2, '2025-01-05T21:40:00Z', [[$this->storeA, 'product', $this->product, '4']]);
        $operations = $this->counts()['operations'];

        self::assertSame('partial', ($this->action([new MockResponse('{"meta":{"size":0,"limit":1000,"offset":0},"rows":[]}')]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT_2));
        self::assertSame(['skipped'], $this->connection->fetchFirstColumn('SELECT status FROM moysklad_store_balance_postings WHERE company_id = ? AND snapshot_id = ?', [self::COMPANY, self::SNAPSHOT_2]));
        self::assertSame($operations, $this->counts()['operations']);
        self::assertSame('54017', $this->balance('stock'));
    }

    public function testReplayKeepsTargetFixedByFirstAttempt(): void
    {
        $this->enable();
        ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT);
        $this->connection->delete('moysklad_store_balance_links', ['company_id' => self::COMPANY, 'store_external_id' => $this->storeB]);
        $rows = $this->counts();

        ($this->action([]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT);
        self::assertSame($rows, $this->counts());
        self::assertSame('54017', $this->balance('stock'));
        self::assertSame('54017', $this->connection->fetchOne('SELECT target_minor::text FROM moysklad_store_balance_postings WHERE company_id = ?', [self::COMPANY]));
    }

    public function testInactiveConnectionIsNotPosted(): void
    {
        $this->enable();
        ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT);
        $this->connection->delete('moysklad_store_balance_postings', ['company_id' => self::COMPANY]);
        $this->connection->update('moysklad_connections', ['is_active' => 'false'], ['id' => self::CONNECTION]);

        self::assertSame('skipped', ($this->action([]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT));
        self::assertSame(0, $this->counts()['postings']);
    }

    public function testDisabledWritingOnlyValues(): void
    {
        self::assertSame('valued', ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT));
        self::assertCount(2, $this->valuations());
        self::assertSame(0, $this->counts()['postings']);
        self::assertSame(1, $this->counts()['operations']);
    }

    public function testMissingCostPermissionFailsRunWithoutPosting(): void
    {
        $this->enable();
        try {
            ($this->action([new MockResponse('{"meta":{"size":1,"limit":1000,"offset":0},"rows":[{"meta":{"type":"product","href":"https://api.moysklad.ru/api/remap/1.2/entity/product/'.$this->product.'"},"stock":2}]}')]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT);
            self::fail('Missing cost must fail the run.');
        } catch (StockSyncException $error) {
            self::assertSame('forbidden', $error->category);
        }
        self::assertSame(['failed', 'forbidden'], array_values($this->connection->fetchAssociative('SELECT status, error_reason FROM moysklad_store_balance_runs WHERE company_id = ?', [self::COMPANY]) ?: []));
        self::assertSame([], $this->valuations());
        self::assertSame(1, $this->counts()['operations']);
    }

    public function testActorWithoutRightsIsSkipped(): void
    {
        $this->enable();
        $stranger = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $this->em->persist($stranger);
        $this->em->flush();
        $this->connection->update('moysklad_store_balance_settings', ['actor_user_id' => (string) $stranger->getId()], ['company_id' => self::COMPANY]);

        self::assertSame('skipped', ($this->action([$this->costs()]))(self::COMPANY, self::CONNECTION, self::SNAPSHOT));
        self::assertSame('skipped', $this->connection->fetchOne('SELECT status FROM moysklad_store_balance_postings WHERE company_id = ?', [self::COMPANY]));
        self::assertSame(1, $this->counts()['operations']);
    }

    public function testAmountRoundsHalfAwayFromZero(): void
    {
        self::assertSame('2', ValueStoreStocksAction::amountMinor('3', '0.5'));
        self::assertSame('-2', ValueStoreStocksAction::amountMinor('-3', '0.5'));
        self::assertSame('1', ValueStoreStocksAction::amountMinor('1', '1.4999999999'));
    }

    private function enable(): void
    {
        $this->connection->insert('moysklad_store_balance_settings', ['company_id' => self::COMPANY, 'connection_id' => self::CONNECTION, 'actor_user_id' => $this->owner,
            'counter_account_id' => $this->accounts['equity'], 'enabled' => 'true', 'updated_at' => '2025-01-01 00:00:00']);
    }

    private function costs(): MockResponse
    {
        return new MockResponse('{"meta":{"size":2,"limit":1000,"offset":0},"rows":['
            .'{"meta":{"type":"product","href":"https://api.moysklad.ru/api/remap/1.2/entity/product/'.$this->product.'"},"price":15433.3333},'
            .'{"meta":{"type":"variant","href":"https://api.moysklad.ru/api/remap/1.2/entity/variant/'.$this->variant.'"},"price":0}]}');
    }

    /** @param list<MockResponse> $responses */
    private function action(array $responses): ValueStoreStocksAction
    {
        $container = self::getContainer();

        return new ValueStoreStocksAction($this->connection, $container->get(MoySkladConnectionWriteRepository::class), $container->get(ConnectionTokenCodec::class),
            new MoySkladClient(new MockHttpClient($responses), 'https://example.test/api/remap/1.2'), new StockReportPageParser(), $container->get(BalanceFacade::class),
            new MockClock('2025-01-05 21:40:00'), new NullLogger());
    }

    /** @param list<array{string, string, string, string}> $lines склад, тип, ассортимент, остаток */
    private function snapshot(string $id, string $startedAt, array $lines): void
    {
        $started = new \DateTimeImmutable($startedAt);
        $snapshot = new MoySkladStockSnapshot($id, self::COMPANY, self::CONNECTION, $started);
        $this->em->persist($snapshot);
        $this->em->flush();
        foreach ($lines as [$store, $type, $assortment, $stock]) {
            $this->em->persist(new MoySkladStockSnapshotLine(Uuid::uuid7()->toString(), self::COMPANY, self::CONNECTION, $id, $store, $type, $assortment, $stock, '0', '0'));
        }
        $this->em->flush();
        $snapshot->complete($started->modify('+1 minute'));
        $this->em->flush();
    }

    /** @return list<array<string, mixed>> */
    private function valuations(): array
    {
        return $this->connection->fetchAllAssociative('SELECT store_external_id, valuation_date, amount_minor, unpriced_positions FROM moysklad_store_valuations WHERE company_id = ? ORDER BY store_external_id', [self::COMPANY]);
    }

    /** @return array{valuations: int, postings: int, operations: int} */
    private function counts(): array
    {
        return [
            'valuations' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_store_valuations WHERE company_id = ?', [self::COMPANY]),
            'postings' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_store_balance_postings WHERE company_id = ?', [self::COMPANY]),
            'operations' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_operations WHERE company_id = ?', [self::COMPANY]),
        ];
    }

    private function balance(string $account): string
    {
        return (string) $this->connection->fetchOne('SELECT balance FROM balance_account_states WHERE company_id = ? AND account_id = ?', [self::COMPANY, $this->accounts[$account]]);
    }
}
