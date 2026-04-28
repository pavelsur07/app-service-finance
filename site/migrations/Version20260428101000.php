<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428101000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inventory: create snapshot sessions and raw snapshots tables with partial indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_snapshot_sessions (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                source VARCHAR NOT NULL,
                trigger_type VARCHAR NOT NULL,
                triggered_by UUID DEFAULT NULL,
                status VARCHAR NOT NULL DEFAULT 'pending',
                started_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                completed_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL,
                expected_pages INT DEFAULT NULL,
                received_pages INT NOT NULL DEFAULT 0,
                error_message TEXT DEFAULT NULL,
                correlation_id UUID NOT NULL,
                created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_inventory_sessions_company_source_started ON inventory_snapshot_sessions (company_id, source, started_at)');
        $this->addSql('CREATE INDEX idx_inventory_sessions_company_status_started ON inventory_snapshot_sessions (company_id, status, started_at)');
        $this->addSql("CREATE INDEX idx_inventory_sessions_active ON inventory_snapshot_sessions (status) WHERE status IN ('pending', 'in_progress')");
        $this->addSql('CREATE INDEX idx_inventory_sessions_correlation ON inventory_snapshot_sessions (correlation_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_raw_snapshots (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                snapshot_session_id UUID NOT NULL,
                source VARCHAR NOT NULL,
                source_endpoint VARCHAR(500) NOT NULL,
                request_params JSONB NOT NULL,
                response_status INT NOT NULL,
                response_body JSONB NOT NULL,
                page_number INT DEFAULT NULL,
                fetched_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                fetch_duration_ms INT NOT NULL,
                is_processed BOOLEAN NOT NULL DEFAULT FALSE,
                processed_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL,
                processing_error TEXT DEFAULT NULL,
                correlation_id UUID NOT NULL,
                created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_inventory_raw_company_source_fetched ON inventory_raw_snapshots (company_id, source, fetched_at)');
        $this->addSql('CREATE INDEX idx_inventory_raw_company_session ON inventory_raw_snapshots (company_id, snapshot_session_id)');
        $this->addSql('CREATE INDEX idx_inventory_raw_session_page ON inventory_raw_snapshots (snapshot_session_id, page_number)');
        $this->addSql('CREATE INDEX idx_inventory_raw_unprocessed ON inventory_raw_snapshots (is_processed) WHERE is_processed = false');
        $this->addSql('CREATE INDEX idx_inventory_raw_correlation ON inventory_raw_snapshots (correlation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_sessions_company_source_started');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_sessions_company_status_started');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_sessions_active');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_sessions_correlation');

        $this->addSql('DROP INDEX IF EXISTS idx_inventory_raw_company_source_fetched');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_raw_company_session');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_raw_session_page');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_raw_unprocessed');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_raw_correlation');

        $this->addSql('DROP TABLE IF EXISTS inventory_raw_snapshots');
        $this->addSql('DROP TABLE IF EXISTS inventory_snapshot_sessions');
    }
}
