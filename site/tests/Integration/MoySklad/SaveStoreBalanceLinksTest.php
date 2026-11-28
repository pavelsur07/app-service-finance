<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\Balance\Application\SeedBalanceStructureAction;
use App\Balance\Facade\BalanceFacade;
use App\MoySklad\Application\Action\SaveStoreBalanceLinkAction;
use App\MoySklad\Application\Action\SaveStoreBalanceLinksAction;
use App\MoySklad\Exception\StoreBalanceException;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Builders\MoySklad\MoySkladStoreBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/** Пакетное сохранение назначений складов с вкладки «Склады»: только изменённое и только если склад не изменили после показа. */
final class SaveStoreBalanceLinksTest extends IntegrationTestCase
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

    public function testSavesOnlyChangedRowsSkipsStaleAndRepeatIsNoop(): void
    {
        $save = self::getContainer()->get(SaveStoreBalanceLinkAction::class);
        $save(self::COMPANY, self::CONNECTION, $this->stores['Ozon'], $this->account('ACC_INV_WAREHOUSE'), false, $this->owner);
        $links = [
            $this->stores['ФФ BoxBuddy'] => ['target' => $this->account('ACC_INV_FF'), 'was' => ''],
            $this->stores['Брак'] => ['target' => 'excluded', 'was' => ''],
            $this->stores['Газелька'] => ['target' => '', 'was' => ''],
            $this->stores['Ozon'] => ['target' => $this->account('ACC_INV_OZON'), 'was' => ''], // показан без счёта, назначен в другом окне
        ];
        $batch = self::getContainer()->get(SaveStoreBalanceLinksAction::class);

        self::assertSame(['saved' => 2, 'skipped' => 1, 'failed' => []], $batch(self::COMPANY, self::CONNECTION, $links, $this->owner));
        $expected = [
            $this->stores['ФФ BoxBuddy'] => ['balance_account_id' => $this->account('ACC_INV_FF'), 'excluded' => false],
            $this->stores['Брак'] => ['balance_account_id' => null, 'excluded' => true],
            $this->stores['Ozon'] => ['balance_account_id' => $this->account('ACC_INV_WAREHOUSE'), 'excluded' => false],
        ];
        self::assertEquals($expected, $this->links(), 'Назначение Ozon из другого окна не перезаписано, «Газелька» без изменений не тронута.');

        $links = [
            $this->stores['ФФ BoxBuddy'] => ['target' => $this->account('ACC_INV_FF'), 'was' => $this->account('ACC_INV_FF')],
            $this->stores['Брак'] => ['target' => 'excluded', 'was' => 'excluded'],
            $this->stores['Ozon'] => ['target' => $this->account('ACC_INV_OZON'), 'was' => $this->account('ACC_INV_WAREHOUSE')],
        ];
        self::assertSame(['saved' => 1, 'skipped' => 0, 'failed' => []], $batch(self::COMPANY, self::CONNECTION, $links, $this->owner));
        self::assertSame($this->account('ACC_INV_OZON'), $this->links()[$this->stores['Ozon']]['balance_account_id']);
        $before = $this->links();
        self::assertSame(['saved' => 1, 'skipped' => 0, 'failed' => []], $batch(self::COMPANY, self::CONNECTION, $links, $this->owner), 'Повтор той же отправки — успех, не «изменено в другом окне».');
        self::assertEquals($before, $this->links());

        $links = [$this->stores['Брак'] => ['target' => '', 'was' => 'excluded']];
        self::assertSame(1, $batch(self::COMPANY, self::CONNECTION, $links, $this->owner)['saved']);
        self::assertArrayNotHasKey($this->stores['Брак'], $this->links(), 'Пустой выбор снимает назначение.');
    }

    public function testRefusedStoreDoesNotRollBackOthers(): void
    {
        $result = (self::getContainer()->get(SaveStoreBalanceLinksAction::class))(self::COMPANY, self::CONNECTION, [
            $this->stores['Газелька'] => ['target' => $this->account('ACC_INV_ADJUSTMENT'), 'was' => ''], // пассив — отказ,
            $this->stores['Брак'] => ['target' => 'excluded', 'was' => ''],
        ], $this->owner);

        self::assertSame(1, $result['saved']);
        self::assertArrayHasKey($this->stores['Газелька'], $result['failed']);
        self::assertSame([$this->stores['Брак']], array_keys($this->links()));
    }

    public function testUserWithoutBalanceRightsSavesNothing(): void
    {
        $stranger = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $this->em->persist($stranger);
        $this->em->flush();
        try {
            (self::getContainer()->get(SaveStoreBalanceLinksAction::class))(self::COMPANY, self::CONNECTION, [$this->stores['Брак'] => ['target' => 'excluded', 'was' => '']], (string) $stranger->getId());
            self::fail('User without Balance posting rights must not save links.');
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
