<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance\Application;

use App\Balance\Application\EnsureBalanceAccountsAction;
use App\Balance\Application\SeedBalanceStructureAction;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class EnsureBalanceAccountsActionTest extends IntegrationTestCase
{
    public function testCreatesTemplateAccountsOnMoneyArticlesOnce(): void
    {
        $companyId = $this->seededCompany();
        $ensure = self::getContainer()->get(EnsureBalanceAccountsAction::class);

        self::assertSame(2, $ensure($companyId));
        self::assertSame(0, $ensure($companyId));

        self::assertSame([
            ['article' => 'CASH_BANK', 'code' => 'ACC_MAIN_BANK', 'name' => 'Расчётный счёт (основной)', 'allow_negative' => false, 'balance' => '0'],
            ['article' => 'CASH_REGISTER', 'code' => 'ACC_MAIN_REGISTER', 'name' => 'Касса (основная)', 'allow_negative' => false, 'balance' => '0'],
        ], $this->accounts($companyId));
        self::assertSame(2, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM balance_audit_events WHERE company_id=? AND object_type='account' AND action='system_seed' AND author_id IS NULL",
            [$companyId],
        ));
        self::assertFalse((bool) $this->connection->fetchOne('SELECT initialized FROM balance_books WHERE company_id=?', [$companyId]));
    }

    public function testCompanyWithOwnAccountIsLeftAsIs(): void
    {
        $companyId = $this->seededCompany();
        $articleId = (string) $this->connection->fetchOne("SELECT id FROM balance_articles WHERE company_id=? AND code='CASH_PAYMENT_SYSTEMS'", [$companyId]);
        $this->connection->insert('balance_accounts', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'article_id' => $articleId, 'code' => 'OWN', 'name' => 'Свой', 'allow_negative' => 0, 'is_archived' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

        self::assertSame(0, (self::getContainer()->get(EnsureBalanceAccountsAction::class))($companyId));
        self::assertSame(['OWN'], $this->connection->fetchFirstColumn('SELECT code FROM balance_accounts WHERE company_id=?', [$companyId]));
    }

    public function testArchivedArticleIsSkippedWithoutFailing(): void
    {
        $companyId = $this->seededCompany();
        $this->connection->executeStatement("UPDATE balance_articles SET is_archived=true WHERE company_id=? AND code='CASH_REGISTER'", [$companyId]);

        self::assertSame(1, (self::getContainer()->get(EnsureBalanceAccountsAction::class))($companyId));
        self::assertSame(['ACC_MAIN_BANK'], $this->connection->fetchFirstColumn('SELECT code FROM balance_accounts WHERE company_id=?', [$companyId]));
    }

    public function testCompanyWithoutBookIsSkipped(): void
    {
        $companyId = $this->createCompany();

        self::assertSame(0, (self::getContainer()->get(EnsureBalanceAccountsAction::class))($companyId));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_accounts WHERE company_id=?', [$companyId]));
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

    /**
     * @return list<array{article: string, code: string, name: string, allow_negative: bool, balance: string}>
     */
    private function accounts(string $companyId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ar.code AS article, a.code, a.name, a.allow_negative, s.balance
               FROM balance_accounts a
               JOIN balance_articles ar ON ar.id = a.article_id AND ar.company_id = a.company_id
               JOIN balance_account_states s ON s.account_id = a.id AND s.company_id = a.company_id
              WHERE a.company_id = ?
              ORDER BY a.code',
            [$companyId],
        );

        return array_map(static fn (array $row): array => [
            'article' => (string) $row['article'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'allow_negative' => (bool) $row['allow_negative'],
            'balance' => (string) $row['balance'],
        ], $rows);
    }
}
