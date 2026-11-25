<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Application\Action\SaveStoreBalanceLinkAction;
use App\MoySklad\Application\Action\SaveStoreBalanceSettingsAction;
use App\MoySklad\Exception\StoreBalanceException;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Builders\MoySklad\MoySkladStoreBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class StoreBalanceSetupTest extends IntegrationTestCase
{
    private const COMPANY = MoySkladConnectionBuilder::DEFAULT_COMPANY_ID;
    private const CONNECTION = MoySkladConnectionBuilder::DEFAULT_ID;
    private string $owner;
    private string $store;
    /** @var array<string, string> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY)->withOwner($owner)->build();
        $otherOwner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $other = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($otherOwner)->build();
        $store = MoySkladStoreBuilder::aStore()->build();
        foreach ([$owner, $company, $otherOwner, $other, MoySkladConnectionBuilder::aConnection()->build(), $store] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->owner = (string) $owner->getId();
        $this->store = $store->getExternalId();
        $this->accounts['stock'] = $this->account(self::COMPANY, 'asset', 'Склад офис');
        $this->accounts['equity'] = $this->account(self::COMPANY, 'passive', 'Корректировка запасов');
        $this->accounts['archived'] = $this->account(self::COMPANY, 'asset', 'Старый склад', true);
        $this->accounts['foreign'] = $this->account((string) $other->getId(), 'asset', 'Чужой склад');
    }

    public function testAssignsAssetAccountAndRepeatKeepsOneRow(): void
    {
        $this->link($this->accounts['stock']);
        $this->link($this->accounts['stock']);

        self::assertSame([['balance_account_id' => $this->accounts['stock'], 'excluded' => false]], $this->links());
    }

    public function testExcludedAndUnassignedStores(): void
    {
        $this->link(null, true);
        self::assertSame([['balance_account_id' => null, 'excluded' => true]], $this->links());

        $this->link(null);
        self::assertSame([], $this->links());
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenAccounts(): iterable
    {
        yield 'passive account' => ['equity'];
        yield 'archived account' => ['archived'];
        yield 'foreign company account' => ['foreign'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenAccounts')]
    public function testRejectsAccountsThatCannotHoldStock(string $account): void
    {
        try {
            $this->link($this->accounts[$account]);
            self::fail('Account must be rejected.');
        } catch (StoreBalanceException $error) {
            self::assertSame('account_unavailable', $error->reason);
        }
        self::assertSame([], $this->links());
    }

    public function testRejectsStoreOfOtherConnection(): void
    {
        $this->expectException(StoreBalanceException::class);
        (self::getContainer()->get(SaveStoreBalanceLinkAction::class))(self::COMPANY, self::CONNECTION, Uuid::uuid4()->toString(), $this->accounts['stock'], false, $this->owner);
    }

    public function testEnablingRequiresCounterAccountAndPostingRights(): void
    {
        $settings = self::getContainer()->get(SaveStoreBalanceSettingsAction::class);
        try {
            $settings(self::COMPANY, self::CONNECTION, null, true, $this->owner);
            self::fail('Counter account is required.');
        } catch (StoreBalanceException $error) {
            self::assertSame('counter_required', $error->reason);
        }
        $stranger = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $this->em->persist($stranger);
        $this->em->flush();
        try {
            $settings(self::COMPANY, self::CONNECTION, $this->accounts['equity'], true, (string) $stranger->getId());
            self::fail('Author must be able to post.');
        } catch (StoreBalanceException $error) {
            self::assertSame('actor_forbidden', $error->reason);
        }

        $settings(self::COMPANY, self::CONNECTION, $this->accounts['equity'], true, $this->owner);
        $settings(self::COMPANY, self::CONNECTION, $this->accounts['equity'], true, $this->owner);
        self::assertSame([['counter_account_id' => $this->accounts['equity'], 'enabled' => true, 'actor_user_id' => $this->owner]],
            $this->connection->fetchAllAssociative('SELECT counter_account_id, enabled, actor_user_id FROM moysklad_store_balance_settings WHERE company_id = ?', [self::COMPANY]));

        $settings(self::COMPANY, self::CONNECTION, null, false, $this->owner);
        self::assertFalse((bool) $this->connection->fetchOne('SELECT enabled FROM moysklad_store_balance_settings WHERE company_id = ?', [self::COMPANY]));
    }

    public function testOnlyBalancePosterCanAssignAccounts(): void
    {
        $stranger = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $this->em->persist($stranger);
        $this->em->flush();
        try {
            (self::getContainer()->get(SaveStoreBalanceLinkAction::class))(self::COMPANY, self::CONNECTION, $this->store, $this->accounts['stock'], false, (string) $stranger->getId());
            self::fail('Marketplace writer without Balance rights must not assign accounts.');
        } catch (StoreBalanceException $error) {
            self::assertSame('actor_forbidden', $error->reason);
        }
        self::assertSame([], $this->links());
    }

    public function testAccountBelongsToOneConnectionAndIsNotCounter(): void
    {
        $second = MoySkladConnectionBuilder::aConnection()->withIndex(2)->build()->setName('Второй кабинет');
        $this->em->persist($second);
        $this->em->flush();
        $secondStore = MoySkladStoreBuilder::aStore()->withIndex(9)->withTenant(self::COMPANY, $second->getId())->build();
        $this->em->persist($secondStore);
        $this->em->flush();
        $this->link($this->accounts['stock']);
        try {
            (self::getContainer()->get(SaveStoreBalanceLinkAction::class))(self::COMPANY, $second->getId(), $secondStore->getExternalId(), $this->accounts['stock'], false, $this->owner);
            self::fail('Account of another connection must be rejected.');
        } catch (StoreBalanceException $error) {
            self::assertSame('account_taken', $error->reason);
        }

        $settings = self::getContainer()->get(SaveStoreBalanceSettingsAction::class);
        try {
            $settings(self::COMPANY, self::CONNECTION, $this->accounts['stock'], false, $this->owner);
            self::fail('Store account cannot be the counter account.');
        } catch (StoreBalanceException $error) {
            self::assertSame('account_is_store', $error->reason);
        }
        $money = $this->account(self::COMPANY, 'asset', 'Расчётный счёт');
        $settings(self::COMPANY, $second->getId(), $money, false, $this->owner);
        try {
            $this->link($money);
            self::fail('Counter account cannot hold a store.');
        } catch (StoreBalanceException $error) {
            self::assertSame('account_is_counter', $error->reason);
        }
    }

    private function link(?string $account, bool $excluded = false): void
    {
        (self::getContainer()->get(SaveStoreBalanceLinkAction::class))(self::COMPANY, self::CONNECTION, $this->store, $account, $excluded, $this->owner);
    }

    /** @return list<array<string, mixed>> */
    private function links(): array
    {
        return $this->connection->fetchAllAssociative('SELECT balance_account_id, excluded FROM moysklad_store_balance_links WHERE company_id = ?', [self::COMPANY]);
    }

    private function account(string $companyId, string $type, string $name, bool $archived = false): string
    {
        $articleId = Uuid::uuid7()->toString();
        $accountId = Uuid::uuid7()->toString();
        $this->connection->insert('balance_articles', ['id' => $articleId, 'company_id' => $companyId, 'name' => $name.' (статья)', 'type' => $type, 'kind' => 'article', 'level' => 1, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
        $this->connection->insert('balance_accounts', ['id' => $accountId, 'company_id' => $companyId, 'article_id' => $articleId, 'code' => substr($accountId, -12), 'name' => $name, 'is_archived' => $archived ? 'true' : 'false', 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);

        return $accountId;
    }
}
