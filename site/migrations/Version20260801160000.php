<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Expand-фаза разбивки транзакции ДДС на несколько категорий.
 *
 * Только DDL: таблица строк разбивки создаётся пустой, никто её ещё не читает.
 * Данные переносит отдельная команда app:cash:backfill-transaction-splits.
 * Колонка cash_transaction.cashflow_category_id остаётся источником правды
 * до переключения читателей — см. docs/tasks/cash-transaction-splits/plan.md.
 */
final class Version20260801160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cash_transaction_split table (expand phase of cashflow category splits)';
    }

    public function up(Schema $schema): void
    {
        $this->assertPostgres();

        $this->addSql(<<<'SQL'
            CREATE TABLE cash_transaction_split (
                id UUID NOT NULL,
                cash_transaction_id UUID NOT NULL,
                company_id UUID NOT NULL,
                cashflow_category_id UUID NOT NULL,
                amount NUMERIC(18, 2) NOT NULL,
                source VARCHAR(16) NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        // Отдельного индекса по cash_transaction_id нет намеренно: он был бы левым
        // префиксом уникального индекса ниже и только удорожал бы вставку.
        $this->addSql('CREATE INDEX idx_cts_company_category ON cash_transaction_split (company_id, cashflow_category_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_cts_tx_category ON cash_transaction_split (cash_transaction_id, cashflow_category_id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE cash_transaction_split
                ADD CONSTRAINT fk_cts_transaction
                FOREIGN KEY (cash_transaction_id) REFERENCES cash_transaction (id)
                ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE cash_transaction_split
                ADD CONSTRAINT fk_cts_category
                FOREIGN KEY (cashflow_category_id) REFERENCES "cashflow_categories" (id)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE cash_transaction_split
                ADD CONSTRAINT chk_cts_amount_positive CHECK (amount > 0)
        SQL);

        // source объявлен единственным источником правды о происхождении категоризации,
        // поэтому набор значений закреплён на уровне БД, а не только в PHP-enum.
        $this->addSql(<<<'SQL'
            ALTER TABLE cash_transaction_split
                ADD CONSTRAINT chk_cts_source CHECK (source IN ('manual', 'auto', 'import'))
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->assertPostgres();

        $this->addSql('DROP TABLE cash_transaction_split');
    }

    private function assertPostgres(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );
    }
}
