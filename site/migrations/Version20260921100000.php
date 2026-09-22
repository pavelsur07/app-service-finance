<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tenant-scoped MoySklad stores and immutable stock snapshots.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_moysklad_variants_tenant_external ON moysklad_variants (company_id, connection_id, external_id)');

        $this->addSql('CREATE TABLE moysklad_stores (id UUID NOT NULL, company_id UUID NOT NULL, connection_id UUID NOT NULL, external_id UUID NOT NULL, name VARCHAR(255) NOT NULL, external_code VARCHAR(255) NOT NULL, code VARCHAR(255) DEFAULT NULL, path_name VARCHAR(4096) NOT NULL, archived BOOLEAN NOT NULL, source_updated_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL, loaded_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_moysklad_stores_connection_external ON moysklad_stores (connection_id, external_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_moysklad_stores_tenant_external ON moysklad_stores (company_id, connection_id, external_id)');
        $this->addSql('ALTER TABLE moysklad_stores ADD CONSTRAINT fk_moysklad_stores_connection FOREIGN KEY (company_id, connection_id) REFERENCES moysklad_connections (company_id, id) ON DELETE RESTRICT');

        $this->addSql("CREATE TABLE moysklad_stock_snapshots (id UUID NOT NULL, company_id UUID NOT NULL, connection_id UUID NOT NULL, status VARCHAR(16) NOT NULL, started_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL, completed_at TIMESTAMP(3) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT chk_moysklad_stock_snapshots_status CHECK (status IN ('building', 'completed', 'failed')), CONSTRAINT chk_moysklad_stock_snapshots_completed CHECK ((status = 'building' AND completed_at IS NULL) OR (status IN ('completed', 'failed') AND completed_at IS NOT NULL)))");
        $this->addSql('CREATE UNIQUE INDEX uniq_moysklad_stock_snapshots_tenant_id ON moysklad_stock_snapshots (company_id, connection_id, id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_moysklad_stock_snapshots_building ON moysklad_stock_snapshots (connection_id) WHERE status = 'building'");
        $this->addSql("CREATE INDEX idx_moysklad_stock_snapshots_completed ON moysklad_stock_snapshots (company_id, connection_id, completed_at) WHERE status = 'completed'");
        $this->addSql('ALTER TABLE moysklad_stock_snapshots ADD CONSTRAINT fk_moysklad_stock_snapshots_connection FOREIGN KEY (company_id, connection_id) REFERENCES moysklad_connections (company_id, id) ON DELETE RESTRICT');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION moysklad_stock_snapshots_guard_transition() RETURNS TRIGGER AS $$
            BEGIN
                IF OLD.status IN ('completed', 'failed') THEN
                    RAISE EXCEPTION 'Terminal stock snapshots are immutable' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql('CREATE TRIGGER trg_moysklad_stock_snapshots_guard_transition BEFORE UPDATE ON moysklad_stock_snapshots FOR EACH ROW EXECUTE FUNCTION moysklad_stock_snapshots_guard_transition()');

        $this->addSql('CREATE TABLE moysklad_stock_snapshot_lines (id UUID NOT NULL, company_id UUID NOT NULL, connection_id UUID NOT NULL, snapshot_id UUID NOT NULL, store_external_id UUID NOT NULL, product_external_id UUID DEFAULT NULL, variant_external_id UUID DEFAULT NULL, stock NUMERIC(30, 10) NOT NULL, reserve NUMERIC(30, 10) NOT NULL, in_transit NUMERIC(30, 10) NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_moysklad_stock_lines_assortment CHECK ((product_external_id IS NOT NULL AND variant_external_id IS NULL) OR (product_external_id IS NULL AND variant_external_id IS NOT NULL)))');
        $this->addSql('CREATE INDEX idx_moysklad_stock_lines_snapshot ON moysklad_stock_snapshot_lines (snapshot_id)');
        $this->addSql('CREATE INDEX idx_moysklad_stock_lines_store ON moysklad_stock_snapshot_lines (company_id, connection_id, store_external_id)');
        $this->addSql('CREATE INDEX idx_moysklad_stock_lines_product ON moysklad_stock_snapshot_lines (company_id, connection_id, product_external_id)');
        $this->addSql('CREATE INDEX idx_moysklad_stock_lines_variant ON moysklad_stock_snapshot_lines (company_id, connection_id, variant_external_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_moysklad_stock_lines_product ON moysklad_stock_snapshot_lines (snapshot_id, store_external_id, product_external_id) WHERE product_external_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_moysklad_stock_lines_variant ON moysklad_stock_snapshot_lines (snapshot_id, store_external_id, variant_external_id) WHERE variant_external_id IS NOT NULL');
        $this->addSql('ALTER TABLE moysklad_stock_snapshot_lines ADD CONSTRAINT fk_moysklad_stock_lines_snapshot FOREIGN KEY (company_id, connection_id, snapshot_id) REFERENCES moysklad_stock_snapshots (company_id, connection_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE moysklad_stock_snapshot_lines ADD CONSTRAINT fk_moysklad_stock_lines_store FOREIGN KEY (company_id, connection_id, store_external_id) REFERENCES moysklad_stores (company_id, connection_id, external_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE moysklad_stock_snapshot_lines ADD CONSTRAINT fk_moysklad_stock_lines_product FOREIGN KEY (company_id, connection_id, product_external_id) REFERENCES moysklad_products (company_id, connection_id, external_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE moysklad_stock_snapshot_lines ADD CONSTRAINT fk_moysklad_stock_lines_variant FOREIGN KEY (company_id, connection_id, variant_external_id) REFERENCES moysklad_variants (company_id, connection_id, external_id) ON DELETE RESTRICT');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION moysklad_stock_lines_require_building() RETURNS TRIGGER AS $$
            DECLARE
                snapshot_status VARCHAR(16);
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    SELECT status INTO snapshot_status FROM moysklad_stock_snapshots WHERE id = OLD.snapshot_id FOR UPDATE;
                    IF snapshot_status IS DISTINCT FROM 'building' THEN
                        RAISE EXCEPTION 'Stock snapshot lines are immutable after publication' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF TG_OP = 'UPDATE' AND NEW.snapshot_id IS DISTINCT FROM OLD.snapshot_id THEN
                    RAISE EXCEPTION 'Stock snapshot line cannot change snapshot' USING ERRCODE = '23514';
                END IF;
                IF TG_OP IN ('INSERT', 'UPDATE') THEN
                    SELECT status INTO snapshot_status FROM moysklad_stock_snapshots WHERE id = NEW.snapshot_id FOR UPDATE;
                    IF snapshot_status IS DISTINCT FROM 'building' THEN
                        RAISE EXCEPTION 'Stock snapshot lines require a building snapshot' USING ERRCODE = '23514';
                    END IF;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql('CREATE TRIGGER trg_moysklad_stock_lines_require_building BEFORE INSERT OR UPDATE OR DELETE ON moysklad_stock_snapshot_lines FOR EACH ROW EXECUTE FUNCTION moysklad_stock_lines_require_building()');

        foreach (['moysklad_stores.source_updated_at', 'moysklad_stores.loaded_at', 'moysklad_stock_snapshots.started_at', 'moysklad_stock_snapshots.completed_at'] as $column) {
            $this->addSql(sprintf("COMMENT ON COLUMN %s IS '(DC2Type:datetime_immutable_utc_ms)'", $column));
        }
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('LOCK TABLE moysklad_stores, moysklad_stock_snapshots, moysklad_stock_snapshot_lines IN ACCESS EXCLUSIVE MODE');
        foreach (['moysklad_stores', 'moysklad_stock_snapshots', 'moysklad_stock_snapshot_lines'] as $table) {
            if (0 < (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table))) {
                $this->throwIrreversibleMigrationException('MoySklad stock tables contain data; rollback requires a separate data decision.');
            }
        }
        $this->addSql('DROP TABLE moysklad_stock_snapshot_lines');
        $this->addSql('DROP TABLE moysklad_stock_snapshots');
        $this->addSql('DROP TABLE moysklad_stores');
        $this->addSql('DROP FUNCTION IF EXISTS moysklad_stock_lines_require_building()');
        $this->addSql('DROP FUNCTION IF EXISTS moysklad_stock_snapshots_guard_transition()');
        $this->addSql('DROP INDEX uniq_moysklad_variants_tenant_external');
    }
}
