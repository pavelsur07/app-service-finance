<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Ingestion canonical financial transaction tables';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('CREATE TABLE ingest_counterparties (id UUID NOT NULL, company_id UUID NOT NULL, source VARCHAR(64) NOT NULL, external_key VARCHAR(255) NOT NULL, name VARCHAR(500) NOT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_counterparty_natural ON ingest_counterparties (company_id, source, external_key)');

        $this->addSql("CREATE TABLE ingest_financial_transactions (id UUID NOT NULL, company_id UUID NOT NULL, connection_ref VARCHAR(255) NOT NULL, shop_ref VARCHAR(255) DEFAULT '' NOT NULL, source VARCHAR(64) NOT NULL, external_id VARCHAR(255) NOT NULL, external_updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, operation_group_id UUID NOT NULL, type VARCHAR(64) NOT NULL, direction VARCHAR(8) NOT NULL, amount_minor BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, occurred_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, source_tz VARCHAR(64) DEFAULT 'UTC' NOT NULL, order_ref VARCHAR(255) DEFAULT NULL, payout_ref VARCHAR(255) DEFAULT NULL, counterparty_id UUID DEFAULT NULL, description TEXT DEFAULT NULL, source_data JSONB NOT NULL, raw_record_id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_ftx_natural_key ON ingest_financial_transactions (company_id, source, external_id, type)');
        $this->addSql('CREATE INDEX idx_ftx_company_occurred ON ingest_financial_transactions (company_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_ftx_company_shop_occurred ON ingest_financial_transactions (company_id, shop_ref, occurred_at)');
        $this->addSql('CREATE INDEX idx_ftx_company_group ON ingest_financial_transactions (company_id, operation_group_id)');
        $this->addSql('CREATE INDEX idx_ftx_company_type_occurred ON ingest_financial_transactions (company_id, type, occurred_at)');
        $this->addSql('CREATE INDEX idx_ftx_company_raw ON ingest_financial_transactions (company_id, raw_record_id)');

        $this->addSql('CREATE TABLE ingest_normalization_issues (id UUID NOT NULL, company_id UUID NOT NULL, raw_record_id UUID NOT NULL, operation_group_id UUID DEFAULT NULL, kind VARCHAR(64) NOT NULL, details JSONB NOT NULL, resolved_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_norm_issue_company_kind_resolved ON ingest_normalization_issues (company_id, kind, resolved_at)');
        $this->addSql('CREATE INDEX idx_norm_issue_company_raw ON ingest_normalization_issues (company_id, raw_record_id)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('DROP TABLE ingest_normalization_issues');
        $this->addSql('DROP TABLE ingest_financial_transactions');
        $this->addSql('DROP TABLE ingest_counterparties');
    }
}
