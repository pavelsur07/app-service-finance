<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260809120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the atomic Cash transfer aggregate with unique legs, idempotency, FX metadata, and soft-delete audit';
    }

    public function up(Schema $schema): void
    {
        $this->abortUnlessPostgreSql();

        $this->addSql(<<<'SQL'
            CREATE TABLE cash_transfer (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                source_transaction_id UUID NOT NULL,
                target_transaction_id UUID NOT NULL,
                idempotency_key VARCHAR(128) NOT NULL,
                effective_rate NUMERIC(38, 18) DEFAULT NULL,
                rate_base_currency VARCHAR(3) DEFAULT NULL,
                rate_quote_currency VARCHAR(3) DEFAULT NULL,
                rate_date DATE DEFAULT NULL,
                rate_source VARCHAR(32) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                deleted_by VARCHAR(64) DEFAULT NULL,
                delete_reason VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT chk_cash_transfer_distinct_legs CHECK (source_transaction_id <> target_transaction_id),
                CONSTRAINT chk_cash_transfer_effective_rate CHECK (effective_rate IS NULL OR effective_rate > 0),
                CONSTRAINT chk_cash_transfer_fx_metadata CHECK (
                    (effective_rate IS NULL AND rate_base_currency IS NULL AND rate_quote_currency IS NULL AND rate_date IS NULL AND rate_source IS NULL)
                    OR
                    (effective_rate IS NOT NULL AND rate_base_currency IS NOT NULL AND rate_quote_currency IS NOT NULL AND rate_date IS NOT NULL AND rate_source IS NOT NULL)
                )
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_cash_transfer_company_idempotency ON cash_transfer (company_id, idempotency_key)');
        $this->addSql('CREATE UNIQUE INDEX uniq_cash_transfer_source_transaction ON cash_transfer (source_transaction_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_cash_transfer_target_transaction ON cash_transfer (target_transaction_id)');
        $this->addSql('CREATE INDEX idx_cash_transfer_company_created ON cash_transfer (company_id, created_at)');
        $this->addSql('CREATE INDEX idx_cash_transfer_company_deleted ON cash_transfer (company_id, deleted_at)');
        $this->addSql('ALTER TABLE cash_transfer ADD CONSTRAINT fk_cash_transfer_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE cash_transfer ADD CONSTRAINT fk_cash_transfer_source_transaction FOREIGN KEY (source_transaction_id) REFERENCES cash_transaction (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE cash_transfer ADD CONSTRAINT fk_cash_transfer_target_transaction FOREIGN KEY (target_transaction_id) REFERENCES cash_transaction (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN cash_transfer.rate_date IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN cash_transfer.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN cash_transfer.updated_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN cash_transfer.deleted_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->abortUnlessPostgreSql();

        $this->addSql('DROP TABLE cash_transfer');
    }

    private function abortUnlessPostgreSql(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );
    }
}
