<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260705110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add company overrides and audit for external category mappings';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('CREATE TABLE ingest_external_category_company_mappings (id UUID NOT NULL, external_category_id UUID NOT NULL, company_id UUID NOT NULL, canonical_code VARCHAR(100) NOT NULL, canonical_label VARCHAR(255) NOT NULL, canonical_group VARCHAR(255) NOT NULL, transaction_type VARCHAR(64) NOT NULL, default_direction VARCHAR(8) DEFAULT NULL, sort_order INT DEFAULT 9000 NOT NULL, known BOOLEAN DEFAULT true NOT NULL, status VARCHAR(32) NOT NULL, updated_by UUID DEFAULT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingest_ext_category_company_mapping ON ingest_external_category_company_mappings (external_category_id, company_id)');
        $this->addSql('CREATE INDEX idx_ingest_ext_category_company_mapping_status ON ingest_external_category_company_mappings (company_id, status, updated_at)');
        $this->addSql('ALTER TABLE ingest_external_category_company_mappings ADD CONSTRAINT fk_ingest_ext_category_company_mapping_category FOREIGN KEY (external_category_id) REFERENCES ingest_external_categories (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE ingest_external_category_mapping_audit (id UUID NOT NULL, external_category_id UUID NOT NULL, company_id UUID DEFAULT NULL, scope VARCHAR(16) NOT NULL, action VARCHAR(32) NOT NULL, old_values JSONB DEFAULT NULL, new_values JSONB NOT NULL, updated_by UUID DEFAULT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_ingest_ext_category_mapping_audit_category ON ingest_external_category_mapping_audit (external_category_id, created_at)');
        $this->addSql('CREATE INDEX idx_ingest_ext_category_mapping_audit_company ON ingest_external_category_mapping_audit (company_id, created_at)');
        $this->addSql('ALTER TABLE ingest_external_category_mapping_audit ADD CONSTRAINT fk_ingest_ext_category_mapping_audit_category FOREIGN KEY (external_category_id) REFERENCES ingest_external_categories (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('ALTER TABLE ingest_external_category_mapping_audit DROP CONSTRAINT fk_ingest_ext_category_mapping_audit_category');
        $this->addSql('ALTER TABLE ingest_external_category_company_mappings DROP CONSTRAINT fk_ingest_ext_category_company_mapping_category');
        $this->addSql('DROP TABLE ingest_external_category_mapping_audit');
        $this->addSql('DROP TABLE ingest_external_category_company_mappings');
    }
}
