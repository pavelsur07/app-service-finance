<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\Balance\Application\BalanceLedgerService;
use App\Balance\Facade\BalanceFacade;
use App\Balance\Security\BalanceAccess;
use App\Company\Facade\CompanyFacade;
use App\MoySklad\Application\Action\ValueStoreStocksAction;
use App\MoySklad\Application\StockReportPageParser;
use App\MoySklad\Entity\MoySkladStockSnapshot;
use App\MoySklad\Entity\MoySkladStockSnapshotLine;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Exception\StockSyncException;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
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
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Оценка складов по себестоимости и установка остатка счёта Баланса. */
final class ValueStoreStocksActionTest extends IntegrationTestCase
{
    private const COMPANY = MoySkladConnectionBuilder::DEFAULT_COMPANY_ID;
    private const CONNECTION = MoySkladConnectionBuilder::DEFAULT_ID;
    private const SNAPSHOT = '0193f000-0000-7000-8000-000000000001';
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
        $snapshot = new MoySkladStockSnapshot(self::SNAPSHOT, self::COMPANY, self::CONNECTION, new \DateTimeImmutable('2025-01-05T21:30:00Z'));
        foreach ([$user, $company, $connection, $product, $variant, $storeA, $storeB, $snapshot] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->owner = (string) $user->getId();
        $this->product = $product->getExternalId();
        $this->variant = $variant->getExternalId();
        $this->storeA = $storeA->getExternalId();
        $this->storeB = $storeB->getExternalId();
        $this->line($this->storeA, 'product', $this->product, '2');
        $this->line($this->storeA, 'variant', $this->variant, '3');
        $this->line($this->storeB, 'product', $this->product, '1.5');
        $snapshot->complete(new \DateTimeImmutable('2025-01-05T21:31:00Z'));
        $this->em->flush();

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

    private function line(string $store, string $type, string $assortment, string $stock): void
    {
        $this->em->persist(new MoySkladStockSnapshotLine(Uuid::uuid7()->toString(), self::COMPANY, self::CONNECTION, self::SNAPSHOT, $store, $type, $assortment, $stock, '0', '0'));
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
