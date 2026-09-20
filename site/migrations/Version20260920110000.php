<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create MoySklad counterparty snapshots and per-connection sync cursor/run history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE moysklad_counterparties (id UUID NOT NULL, company_id UUID NOT NULL, connection_id UUID NOT NULL, external_id UUID NOT NULL, name VARCHAR(255) NOT NULL, company_type VARCHAR(64) NOT NULL, legal_title VARCHAR(4096) DEFAULT NULL, inn VARCHAR(255) DEFAULT NULL, kpp VARCHAR(255) DEFAULT NULL, ogrn VARCHAR(255) DEFAULT NULL, ogrnip VARCHAR(255) DEFAULT NULL, legal_address VARCHAR(255) DEFAULT NULL, archived BOOLEAN NOT NULL, source_updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, loaded_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_moysklad_counterparties_connection_external ON moysklad_counterparties (connection_id, external_id)');
        $this->addSql('CREATE INDEX idx_moysklad_counterparties_company_connection ON moysklad_counterparties (company_id, connection_id)');
        $this->addSql('ALTER TABLE moysklad_counterparties ADD CONSTRAINT fk_moysklad_counterparties_connection FOREIGN KEY (connection_id) REFERENCES moysklad_connections (id) ON DELETE RESTRICT');

        $this->addSql('CREATE TABLE moysklad_sync_cursors (id UUID NOT NULL, company_id UUID NOT NULL, connection_id UUID NOT NULL, entity_type VARCHAR(64) NOT NULL, last_completed_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_moysklad_sync_cursors_connection_type ON moysklad_sync_cursors (connection_id, entity_type)');
        $this->addSql('CREATE INDEX idx_moysklad_sync_cursors_company_connection ON moysklad_sync_cursors (company_id, connection_id)');
        $this->addSql('ALTER TABLE moysklad_sync_cursors ADD CONSTRAINT fk_moysklad_sync_cursors_connection FOREIGN KEY (connection_id) REFERENCES moysklad_connections (id) ON DELETE RESTRICT');

        $this->addSql("CREATE TABLE moysklad_sync_runs (id UUID NOT NULL, company_id UUID NOT NULL, connection_id UUID NOT NULL, entity_type VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, started_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, processed INT DEFAULT 0 NOT NULL, created INT DEFAULT 0 NOT NULL, updated INT DEFAULT 0 NOT NULL, unchanged INT DEFAULT 0 NOT NULL, error_category VARCHAR(32) DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT chk_moysklad_runs_status CHECK (status IN ('running', 'succeeded', 'failed')), CONSTRAINT chk_moysklad_runs_counts CHECK (processed >= 0 AND created >= 0 AND updated >= 0 AND unchanged >= 0 AND processed = created + updated + unchanged), CONSTRAINT chk_moysklad_runs_outcome CHECK ((status = 'running' AND finished_at IS NULL AND error_category IS NULL) OR (status = 'succeeded' AND finished_at IS NOT NULL AND error_category IS NULL) OR (status = 'failed' AND finished_at IS NOT NULL AND error_category IS NOT NULL)))");
        $this->addSql('CREATE INDEX idx_moysklad_sync_runs_company_history ON moysklad_sync_runs (company_id, connection_id, entity_type, started_at)');
        $this->addSql('CREATE INDEX idx_moysklad_sync_runs_connection ON moysklad_sync_runs (connection_id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_moysklad_sync_runs_running ON moysklad_sync_runs (connection_id, entity_type) WHERE status = 'running'");
        $this->addSql('ALTER TABLE moysklad_sync_runs ADD CONSTRAINT fk_moysklad_sync_runs_connection FOREIGN KEY (connection_id) REFERENCES moysklad_connections (id) ON DELETE RESTRICT');

        foreach (['moysklad_counterparties.source_updated_at', 'moysklad_counterparties.loaded_at', 'moysklad_sync_cursors.last_completed_at', 'moysklad_sync_runs.started_at', 'moysklad_sync_runs.finished_at'] as $column) {
            $this->addSql(sprintf("COMMENT ON COLUMN %s IS '(DC2Type:datetime_immutable_utc_us)'", $column));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['moysklad_counterparties', 'moysklad_sync_cursors', 'moysklad_sync_runs'] as $table) {
            if (0 < (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table))) {
                $this->throwIrreversibleMigrationException('MoySklad sync tables contain data; rollback requires a separate data decision.');
            }
        }

        $this->addSql('DROP TABLE moysklad_sync_runs');
        $this->addSql('DROP TABLE moysklad_sync_cursors');
        $this->addSql('DROP TABLE moysklad_counterparties');
    }
}
