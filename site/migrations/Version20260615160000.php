<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Ingestion raw record metadata table';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('CREATE TABLE ingest_raw_records (id UUID NOT NULL, company_id UUID NOT NULL, connection_ref VARCHAR(255) NOT NULL, shop_ref VARCHAR(255) NOT NULL, source VARCHAR(64) NOT NULL, resource_type VARCHAR(100) NOT NULL, external_id VARCHAR(255) NOT NULL, storage_path VARCHAR(1024) NOT NULL, hash VARCHAR(64) NOT NULL, byte_size INT NOT NULL, fetched_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, sync_job_id VARCHAR(100) NOT NULL, normalization_status VARCHAR(32) NOT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_ingest_raw_company_source_resource_fetched ON ingest_raw_records (company_id, source, resource_type, fetched_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingest_raw_company_source_external_hash ON ingest_raw_records (company_id, source, external_id, hash)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('DROP TABLE ingest_raw_records');
    }
}
