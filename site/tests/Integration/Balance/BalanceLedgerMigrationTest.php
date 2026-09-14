<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20260914120000;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BalanceLedgerMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3).'/migrations/Version20260914120000.php';

        self::bootKernel();
        $connection = self::getContainer()->get('doctrine')->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $schemaName = 'balance_migration_'.str_replace('-', '', Uuid::uuid7()->toString());
        $this->connection->executeStatement('CREATE SCHEMA '.$schemaName);
        $this->connection->executeStatement('SET LOCAL search_path TO '.$schemaName);
        $this->connection->executeStatement('CREATE TABLE balance_categories (id integer PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE balance_category_links (id integer PRIMARY KEY)');
        $this->connection->executeStatement('INSERT INTO balance_categories VALUES (42)');
        $this->connection->executeStatement('INSERT INTO balance_category_links VALUES (43)');
        $migration = new Version20260914120000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testMigrationPreservesLegacyDataAndCreatesEmptyLedger(): void
    {
        self::assertSame(42, (int) $this->connection->fetchOne('SELECT id FROM balance_categories'));
        self::assertSame(43, (int) $this->connection->fetchOne('SELECT id FROM balance_category_links'));
        foreach (['books', 'articles', 'accounts', 'operations', 'operation_lines', 'account_states', 'periods', 'access_grants', 'audit_events'] as $table) {
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_'.$table));
        }
    }

    public function testAccountCannotReferenceArticleOfAnotherCompany(): void
    {
        [$companyId, $articleId] = $this->insertArticle();
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->connection->insert('balance_accounts', [
            'id' => Uuid::uuid7()->toString(), 'company_id' => Uuid::uuid7()->toString(), 'article_id' => $articleId,
            'code' => 'BANK', 'name' => 'Bank', 'created_at' => '2026-09-14 00:00:00', 'updated_at' => '2026-09-14 00:00:00',
        ]);
    }

    public function testReferencedArticleCannotBeDeleted(): void
    {
        [$companyId, $articleId] = $this->insertArticle();
        $this->connection->insert('balance_accounts', [
            'id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'article_id' => $articleId,
            'code' => 'BANK', 'name' => 'Bank', 'created_at' => '2026-09-14 00:00:00', 'updated_at' => '2026-09-14 00:00:00',
        ]);
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->connection->delete('balance_articles', ['id' => $articleId, 'company_id' => $companyId]);
    }

    public function testRollbackRefusesToDropAccountingHistory(): void
    {
        $migration = new Version20260914120000($this->connection, new NullLogger());
        $this->expectException(IrreversibleMigration::class);
        $migration->down(new Schema());
    }

    /** @return array{string, string} */
    private function insertArticle(): array
    {
        $companyId = Uuid::uuid7()->toString();
        $articleId = Uuid::uuid7()->toString();
        $this->connection->insert('balance_articles', [
            'id' => $articleId, 'company_id' => $companyId, 'name' => 'Money', 'type' => 'asset', 'kind' => 'article', 'level' => 1,
            'created_at' => '2026-09-14 00:00:00', 'updated_at' => '2026-09-14 00:00:00',
        ]);

        return [$companyId, $articleId];
    }
}
