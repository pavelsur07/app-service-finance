<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\Balance\Application\SeedBalanceStructureAction;
use App\Balance\Facade\BalanceFacade;
use App\MoySklad\Application\Action\AcceptStoreAccountSuggestionsAction;
use App\MoySklad\Application\Action\SaveStoreBalanceLinkAction;
use App\MoySklad\Exception\StoreBalanceException;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Builders\MoySklad\MoySkladStoreBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/** «Принять все уверенные»: только склады без назначения, только найденные счета, повтор ничего не меняет. */
final class AcceptStoreAccountSuggestionsTest extends IntegrationTestCase
{
    private const COMPANY = MoySkladConnectionBuilder::DEFAULT_COMPANY_ID;
    private const CONNECTION = MoySkladConnectionBuilder::DEFAULT_ID;
    private string $owner;
    /** @var array<string, string> название склада → external id */
    private array $stores = [];

    protected function setUp(): void
    {
        parent::setUp();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY)->withOwner($owner)->build();
        foreach ([$owner, $company, MoySkladConnectionBuilder::aConnection()->build()] as $entity) {
            $this->em->persist($entity);
        }
        foreach (['ФФ BoxBuddy', 'Брак', 'Газелька', 'Ozon'] as $i => $name) {
            $store = MoySkladStoreBuilder::aStore()->withIndex($i + 1)->withName($name)->build();
            $this->em->persist($store);
            $this->stores[$name] = $store->getExternalId();
        }
        $this->em->flush();
        $this->owner = (string) $owner->getId();
        self::assertTrue(self::getContainer()->get(SeedBalanceStructureAction::class)(self::COMPANY));
        self::getContainer()->get(BalanceFacade::class)->ensureStandardChart(self::COMPANY);
    }

    public function testAcceptsConfidentSuggestionsOnlyForUnassignedStoresAndRepeatIsNoop(): void
    {
        (self::getContainer()->get(SaveStoreBalanceLinkAction::class))(self::COMPANY, self::CONNECTION, $this->stores['Ozon'], $this->account('ACC_INV_WAREHOUSE'), false, $this->owner);
        $accept = self::getContainer()->get(AcceptStoreAccountSuggestionsAction::class);

        self::assertSame(['accepted' => 2, 'failed' => 0], $accept(self::COMPANY, self::CONNECTION, $this->owner));
        $expected = [
            $this->stores['ФФ BoxBuddy'] => ['balance_account_id' => $this->account('ACC_INV_FF'), 'excluded' => false],
            $this->stores['Брак'] => ['balance_account_id' => null, 'excluded' => true],
            $this->stores['Ozon'] => ['balance_account_id' => $this->account('ACC_INV_WAREHOUSE'), 'excluded' => false],
        ];
        self::assertEquals($expected, $this->links(), 'Ручное назначение Ozon не перезаписано, «Газелька» без правила не назначена.');

        self::assertSame(['accepted' => 0, 'failed' => 0], $accept(self::COMPANY, self::CONNECTION, $this->owner));
        self::assertEquals($expected, $this->links());
    }

    public function testUserWithoutBalanceRightsAcceptsNothing(): void
    {
        $stranger = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $this->em->persist($stranger);
        $this->em->flush();
        try {
            (self::getContainer()->get(AcceptStoreAccountSuggestionsAction::class))(self::COMPANY, self::CONNECTION, (string) $stranger->getId());
            self::fail('User without Balance posting rights must not accept suggestions.');
        } catch (StoreBalanceException $error) {
            self::assertSame('actor_forbidden', $error->reason);
        }
        self::assertSame([], $this->links());
    }

    private function account(string $code): string
    {
        return (string) $this->connection->fetchOne('SELECT id FROM balance_accounts WHERE company_id = ? AND code = ?', [self::COMPANY, $code]);
    }

    /** @return array<string, array{balance_account_id: ?string, excluded: bool}> */
    private function links(): array
    {
        $rows = [];
        foreach ($this->connection->fetchAllAssociative('SELECT store_external_id, balance_account_id, excluded FROM moysklad_store_balance_links WHERE company_id = ?', [self::COMPANY]) as $row) {
            $rows[(string) $row['store_external_id']] = ['balance_account_id' => $row['balance_account_id'], 'excluded' => (bool) $row['excluded']];
        }

        return $rows;
    }
}
