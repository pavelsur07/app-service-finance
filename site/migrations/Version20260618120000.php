<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Ingestion cursor and sync job tables';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql("CREATE TABLE ingest_cursors (id UUID NOT NULL, company_id UUID NOT NULL, connection_ref VARCHAR(255) NOT NULL, resource_type VARCHAR(100) NOT NULL, shop_ref VARCHAR(255) DEFAULT '' NOT NULL, cursor_value VARCHAR(1024) DEFAULT '' NOT NULL, last_fetched_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, last_sync_job_id UUID DEFAULT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_ingest_cursor_key ON ingest_cursors (company_id, connection_ref, resource_type, shop_ref)');
        $this->addSql('CREATE INDEX idx_ingest_cursor_company_connection ON ingest_cursors (company_id, connection_ref)');

        $this->addSql("CREATE TABLE ingest_sync_jobs (id UUID NOT NULL, company_id UUID NOT NULL, connection_ref VARCHAR(255) NOT NULL, source VARCHAR(64) NOT NULL, resource_type VARCHAR(100) NOT NULL, shop_ref VARCHAR(255) DEFAULT '' NOT NULL, kind VARCHAR(32) NOT NULL, status VARCHAR(32) DEFAULT 'open' NOT NULL, window_from DATE DEFAULT NULL, window_to DATE DEFAULT NULL, parent_job_id UUID DEFAULT NULL, progress_total INT DEFAULT 0 NOT NULL, progress_done INT DEFAULT 0 NOT NULL, cursor_snapshot VARCHAR(1024) DEFAULT NULL, attempts INT DEFAULT 0 NOT NULL, last_error TEXT DEFAULT NULL, started_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_ingest_sync_job_company_status ON ingest_sync_jobs (company_id, status)');
        $this->addSql('CREATE INDEX idx_ingest_sync_job_resource_status ON ingest_sync_jobs (company_id, connection_ref, resource_type, status)');
        $this->addSql('CREATE INDEX idx_ingest_sync_job_parent ON ingest_sync_jobs (parent_job_id)');
        $this->addSql('CREATE INDEX idx_ingest_sync_job_kind_status ON ingest_sync_jobs (company_id, kind, status)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('DROP TABLE ingest_sync_jobs');
        $this->addSql('DROP TABLE ingest_cursors');
    }
}
