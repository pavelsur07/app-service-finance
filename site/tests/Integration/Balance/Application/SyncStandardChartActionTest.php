<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance\Application;

use App\Balance\Application\SeedBalanceStructureAction;
use App\Balance\Application\SyncStandardChartAction;
use App\Company\Enum\BusinessProfile;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/** Типовой справочник счетов: добор недостающих статей и счетов по коду, однократно на версию шаблона. */
final class SyncStandardChartActionTest extends IntegrationTestCase
{
    private const STANDARD_ACCOUNTS = 28;

    public function testNewCompanyGetsStandardChartOnce(): void
    {
        $companyId = $this->seededCompany();
        $sync = self::getContainer()->get(SyncStandardChartAction::class);

        self::assertSame(self::STANDARD_ACCOUNTS, $sync($companyId));
        $rows = $this->counts($companyId);
        self::assertSame(0, $sync($companyId));
        self::assertSame($rows, $this->counts($companyId));

        $accounts = $this->accounts($companyId);
        self::assertCount(self::STANDARD_ACCOUNTS, $accounts);
        self::assertContains(['article' => 'INV_FULFILLMENT', 'code' => 'ACC_INV_FF', 'name' => 'Склады фулфилмента', 'allow_negative' => false, 'balance' => '0'], $accounts);
        self::assertContains(['article' => 'RETAINED_RESULT', 'code' => 'ACC_INV_ADJUSTMENT', 'name' => 'Корректировка запасов', 'allow_negative' => true, 'balance' => '0'], $accounts);
        self::assertSame(['INV_WAREHOUSE', 'INV_MARKETPLACE', 'INV_FULFILLMENT', 'INV_IN_TRANSIT'], $this->inventoryOrder($companyId));
        self::assertSame(self::STANDARD_ACCOUNTS, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM balance_audit_events WHERE company_id=? AND object_type='account' AND action='system_seed' AND author_id IS NULL", [$companyId]));
        self::assertFalse((bool) $this->connection->fetchOne('SELECT initialized FROM balance_books WHERE company_id=?', [$companyId]));
    }

    public function testExistingStructureWithoutAccountsGetsFulfillmentArticleInPlace(): void
    {
        $companyId = $this->seededCompany();
        $this->connection->executeStatement("DELETE FROM balance_articles WHERE company_id=? AND code='INV_FULFILLMENT'", [$companyId]);

        self::assertSame(self::STANDARD_ACCOUNTS + 1, (self::getContainer()->get(SyncStandardChartAction::class))($companyId));
        self::assertSame(['INV_WAREHOUSE', 'INV_MARKETPLACE', 'INV_FULFILLMENT', 'INV_IN_TRANSIT'], $this->inventoryOrder($companyId));
        self::assertSame(['asset', 'article', 3, 'Склады ФФ (фулфилмент)'], array_values($this->connection->fetchAssociative(
            "SELECT type, kind, level, name FROM balance_articles WHERE company_id=? AND code='INV_FULFILLMENT'", [$companyId]) ?: []));
    }

    public function testUserAccountsAndStandardCodeTakenByUserAreKept(): void
    {
        $companyId = $this->seededCompany();
        $own = $this->ownAccount($companyId, 'CASH_PAYMENT_SYSTEMS', 'OWN', 'Свой');
        $taken = $this->ownAccount($companyId, 'AR_OTHER', 'ACC_INV_OZON', 'Мой счёт с типовым кодом');

        self::assertSame(self::STANDARD_ACCOUNTS - 1, (self::getContainer()->get(SyncStandardChartAction::class))($companyId));
        self::assertSame([['id' => $own, 'name' => 'Свой'], ['id' => $taken, 'name' => 'Мой счёт с типовым кодом']], $this->connection->fetchAllAssociative(
            "SELECT id, name FROM balance_accounts WHERE company_id=? AND code IN ('OWN', 'ACC_INV_OZON') ORDER BY code DESC", [$companyId]));
    }

    public function testArchivedArticlesAreSkippedWithoutFailing(): void
    {
        $companyId = $this->seededCompany();
        $this->connection->executeStatement("DELETE FROM balance_articles WHERE company_id=? AND code='INV_FULFILLMENT'", [$companyId]);
        $this->connection->executeStatement("UPDATE balance_articles SET is_archived=true WHERE company_id=? AND code IN ('CASH_REGISTER', 'INVENTORY')", [$companyId]);

        // Без счёта кассы; «Запасы» в архиве — ни ФФ, ни счетов запасов (их статьи под архивной группой).
        self::assertSame(self::STANDARD_ACCOUNTS - 1 - 5, (self::getContainer()->get(SyncStandardChartAction::class))($companyId));
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM balance_articles WHERE company_id=? AND code='INV_FULFILLMENT'", [$companyId]));
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM balance_accounts WHERE company_id=? AND code='ACC_MAIN_REGISTER'", [$companyId]));
    }

    public function testMissingGroupIsNotRecreated(): void
    {
        $companyId = $this->seededCompany();
        $this->connection->executeStatement("DELETE FROM balance_articles WHERE company_id=? AND code IN ('INV_WAREHOUSE', 'INV_MARKETPLACE', 'INV_FULFILLMENT', 'INV_IN_TRANSIT')", [$companyId]);
        $this->connection->executeStatement("DELETE FROM balance_articles WHERE company_id=? AND code='INVENTORY'", [$companyId]);

        self::assertSame(self::STANDARD_ACCOUNTS - 5, (self::getContainer()->get(SyncStandardChartAction::class))($companyId));
        self::assertSame([], $this->connection->fetchFirstColumn("SELECT code FROM balance_articles WHERE company_id=? AND code LIKE 'INV%'", [$companyId]));
    }

    public function testIncompatibleParentIsSkippedWithoutFailing(): void
    {
        $companyId = $this->seededCompany();
        $this->connection->executeStatement("DELETE FROM balance_articles WHERE company_id=? AND code='INV_FULFILLMENT'", [$companyId]);
        $this->connection->executeStatement("UPDATE balance_articles SET level=4 WHERE company_id=? AND code='INVENTORY'", [$companyId]);

        self::assertSame(self::STANDARD_ACCOUNTS - 1, (self::getContainer()->get(SyncStandardChartAction::class))($companyId));
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM balance_articles WHERE company_id=? AND code='INV_FULFILLMENT'", [$companyId]));
    }

    public function testExistingTreeKeepsTemplateItWasSeededWith(): void
    {
        $companyId = $this->createCompany();
        self::assertTrue((self::getContainer()->get(SeedBalanceStructureAction::class))($companyId, profile: BusinessProfile::UNIVERSAL));

        self::assertSame(24, (self::getContainer()->get(SyncStandardChartAction::class))($companyId, profile: BusinessProfile::MARKETPLACE));
        self::assertSame([], $this->connection->fetchFirstColumn("SELECT code FROM balance_articles WHERE company_id=? AND code IN ('AR_MARKETPLACES', 'INV_MARKETPLACE')", [$companyId]));
    }

    public function testDeletedAfterSyncIsNotRestored(): void
    {
        $companyId = $this->seededCompany();
        $sync = self::getContainer()->get(SyncStandardChartAction::class);
        $sync($companyId);
        $accountId = (string) $this->connection->fetchOne("SELECT id FROM balance_accounts WHERE company_id=? AND code='ACC_INV_FF'", [$companyId]);
        $this->connection->executeStatement('DELETE FROM balance_account_states WHERE account_id=?', [$accountId]);
        $this->connection->executeStatement('DELETE FROM balance_accounts WHERE id=?', [$accountId]);
        $this->connection->executeStatement("DELETE FROM balance_articles WHERE company_id=? AND code='INV_FULFILLMENT'", [$companyId]);

        self::assertSame(0, $sync($companyId));
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM balance_articles WHERE company_id=? AND code='INV_FULFILLMENT'", [$companyId]));
    }

    public function testDryRunCountsWithoutWriting(): void
    {
        $companyId = $this->seededCompany();

        self::assertSame(self::STANDARD_ACCOUNTS, (self::getContainer()->get(SyncStandardChartAction::class))($companyId, dryRun: true));
        self::assertSame(0, $this->counts($companyId)['accounts']);
        self::assertSame(self::STANDARD_ACCOUNTS, (self::getContainer()->get(SyncStandardChartAction::class))($companyId));
    }

    public function testCompanyWithoutBookIsSkipped(): void
    {
        $companyId = $this->createCompany();

        self::assertSame(0, (self::getContainer()->get(SyncStandardChartAction::class))($companyId));
        self::assertSame(0, $this->counts($companyId)['accounts']);
    }

    private function seededCompany(): string
    {
        $companyId = $this->createCompany();
        self::assertTrue((self::getContainer()->get(SeedBalanceStructureAction::class))($companyId));

        return $companyId;
    }

    private function createCompany(): string
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return (string) $company->getId();
    }

    private function ownAccount(string $companyId, string $articleCode, string $code, string $name): string
    {
        $id = Uuid::uuid7()->toString();
        $articleId = (string) $this->connection->fetchOne('SELECT id FROM balance_articles WHERE company_id=? AND code=?', [$companyId, $articleCode]);
        $this->connection->insert('balance_accounts', ['id' => $id, 'company_id' => $companyId, 'article_id' => $articleId, 'code' => $code, 'name' => $name, 'allow_negative' => 0, 'is_archived' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function accounts(string $companyId): array
    {
        return $this->connection->fetchAllAssociative('SELECT c.code AS article, a.code, a.name, a.allow_negative, s.balance::text AS balance FROM balance_accounts a
            JOIN balance_articles c ON c.id = a.article_id JOIN balance_account_states s ON s.account_id = a.id WHERE a.company_id=? ORDER BY a.code', [$companyId]);
    }

    /** @return list<string> */
    private function inventoryOrder(string $companyId): array
    {
        return $this->connection->fetchFirstColumn("SELECT c.code FROM balance_articles c JOIN balance_articles p ON p.id = c.parent_id
            WHERE c.company_id=? AND p.code='INVENTORY' ORDER BY c.sort_order, c.code", [$companyId]);
    }

    /** @return array{articles: int, accounts: int, audit: int} */
    private function counts(string $companyId): array
    {
        return [
            'articles' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_articles WHERE company_id=?', [$companyId]),
            'accounts' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_accounts WHERE company_id=?', [$companyId]),
            'audit' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_audit_events WHERE company_id=?', [$companyId]),
        ];
    }
}
