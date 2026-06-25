<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260625110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Ingestion external category taxonomy tables';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('CREATE TABLE ingest_external_categories (id UUID NOT NULL, source VARCHAR(64) NOT NULL, resource_type VARCHAR(100) NOT NULL, scope VARCHAR(64) NOT NULL, external_type_id VARCHAR(100) DEFAULT NULL, external_name VARCHAR(255) DEFAULT NULL, normalized_key VARCHAR(512) NOT NULL, status VARCHAR(32) NOT NULL, first_seen_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, seen_count INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingest_ext_category_identity ON ingest_external_categories (source, resource_type, scope, normalized_key)');
        $this->addSql('CREATE INDEX idx_ingest_ext_category_status ON ingest_external_categories (status, last_seen_at)');
        $this->addSql('CREATE INDEX idx_ingest_ext_category_source_resource ON ingest_external_categories (source, resource_type)');

        $this->addSql('CREATE TABLE ingest_external_category_mappings (id UUID NOT NULL, external_category_id UUID NOT NULL, canonical_code VARCHAR(100) NOT NULL, canonical_label VARCHAR(255) NOT NULL, canonical_group VARCHAR(255) NOT NULL, transaction_type VARCHAR(64) NOT NULL, default_direction VARCHAR(8) DEFAULT NULL, sort_order INT DEFAULT 9000 NOT NULL, known BOOLEAN DEFAULT true NOT NULL, status VARCHAR(32) NOT NULL, updated_by UUID DEFAULT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingest_ext_category_mapping_category ON ingest_external_category_mappings (external_category_id)');
        $this->addSql('CREATE INDEX idx_ingest_ext_category_mapping_status ON ingest_external_category_mappings (status, updated_at)');
        $this->addSql('ALTER TABLE ingest_external_category_mappings ADD CONSTRAINT fk_ingest_ext_category_mapping_category FOREIGN KEY (external_category_id) REFERENCES ingest_external_categories (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('DROP TABLE ingest_external_category_mappings');
        $this->addSql('DROP TABLE ingest_external_categories');
    }
}
