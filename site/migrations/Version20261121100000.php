<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MoySklad bulk mapping jobs: durable background acceptance of proposals with per-listing results and undo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE moysklad_mapping_bulk_jobs (
                id UUID NOT NULL PRIMARY KEY, company_id UUID NOT NULL, connection_id UUID NOT NULL,
                marketplace VARCHAR(20) NOT NULL, actor_user_id UUID NOT NULL, origin VARCHAR(8) NOT NULL,
                scope VARCHAR(12) NOT NULL, run_id UUID DEFAULT NULL, size_characteristic_id VARCHAR(255) DEFAULT NULL,
                status VARCHAR(12) NOT NULL, after_listing_id UUID DEFAULT NULL,
                total INT DEFAULT NULL, processed INT NOT NULL DEFAULT 0, accepted INT NOT NULL DEFAULT 0,
                skipped INT NOT NULL DEFAULT 0, conflicts INT NOT NULL DEFAULT 0, failed INT NOT NULL DEFAULT 0,
                error_reason VARCHAR(64) DEFAULT NULL,
                created_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL, heartbeat_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL,
                finished_at TIMESTAMP(3) WITHOUT TIME ZONE DEFAULT NULL,
                undo_status VARCHAR(12) DEFAULT NULL, undo_actor_user_id UUID DEFAULT NULL,
                undone INT NOT NULL DEFAULT 0, undo_skipped INT NOT NULL DEFAULT 0,
                undo_finished_at TIMESTAMP(3) WITHOUT TIME ZONE DEFAULT NULL,
                CONSTRAINT uniq_ms_bulk_tenant_id UNIQUE (company_id, id),
                CONSTRAINT fk_ms_bulk_connection FOREIGN KEY (company_id, connection_id) REFERENCES moysklad_connections (company_id, id) ON DELETE RESTRICT,
                CONSTRAINT chk_ms_bulk_marketplace CHECK (marketplace IN ('ozon', 'wildberries')),
                CONSTRAINT chk_ms_bulk_origin CHECK (origin IN ('manual', 'auto')),
                CONSTRAINT chk_ms_bulk_scope CHECK (scope IN ('report', 'selected', 'cabinet')),
                CONSTRAINT chk_ms_bulk_status CHECK (status IN ('queued', 'running', 'completed', 'failed')),
                CONSTRAINT chk_ms_bulk_undo_status CHECK (undo_status IS NULL OR undo_status IN ('queued', 'running', 'completed', 'failed')),
                CONSTRAINT chk_ms_bulk_counters CHECK (processed >= 0 AND accepted >= 0 AND skipped >= 0 AND conflicts >= 0 AND failed >= 0 AND undone >= 0 AND undo_skipped >= 0)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_ms_bulk_scope ON moysklad_mapping_bulk_jobs (company_id, connection_id, marketplace, created_at DESC, id DESC)');
        $this->addSql(<<<'SQL'
            CREATE TABLE moysklad_mapping_bulk_job_items (
                company_id UUID NOT NULL, job_id UUID NOT NULL, listing_id UUID NOT NULL,
                target_type VARCHAR(8) DEFAULT NULL, target_external_id UUID DEFAULT NULL,
                status VARCHAR(12) NOT NULL, message VARCHAR(255) DEFAULT NULL, revision INT DEFAULT NULL,
                undo_status VARCHAR(12) DEFAULT NULL, processed_at TIMESTAMP(3) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (company_id, job_id, listing_id),
                CONSTRAINT fk_ms_bulk_item_job FOREIGN KEY (company_id, job_id) REFERENCES moysklad_mapping_bulk_jobs (company_id, id) ON DELETE RESTRICT,
                CONSTRAINT chk_ms_bulk_item_status CHECK (status IN ('pending', 'accepted', 'skipped', 'conflict', 'failed')),
                CONSTRAINT chk_ms_bulk_item_undo CHECK (undo_status IS NULL OR undo_status IN ('undone', 'skipped'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_ms_bulk_items_status ON moysklad_mapping_bulk_job_items (company_id, job_id, status, listing_id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Bulk mapping audit must be preserved; use a forward migration.');
    }
}
