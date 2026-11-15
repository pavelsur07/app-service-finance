<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tenant-scoped MoySklad listing mapping revisions, without changing Catalog or financial data.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE moysklad_listing_mapping_revisions (
                id UUID NOT NULL, company_id UUID NOT NULL, connection_id UUID NOT NULL,
                listing_id UUID NOT NULL, revision INT NOT NULL, expected_revision INT NOT NULL,
                operation VARCHAR(8) NOT NULL, product_external_id UUID DEFAULT NULL,
                variant_external_id UUID DEFAULT NULL, request_id UUID NOT NULL,
                actor_user_id UUID NOT NULL, created_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL,
                listing_snapshot JSONB NOT NULL, target_snapshot JSONB DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT chk_ms_mapping_revision CHECK (revision > 0 AND expected_revision >= 0 AND revision = expected_revision + 1),
                CONSTRAINT chk_ms_mapping_listing_snapshot CHECK (jsonb_typeof(listing_snapshot) = 'object'),
                CONSTRAINT chk_ms_mapping_target CHECK (
                    (operation = 'set' AND (
                        (product_external_id IS NOT NULL AND variant_external_id IS NULL) OR
                        (product_external_id IS NULL AND variant_external_id IS NOT NULL)
                    ) AND target_snapshot IS NOT NULL AND jsonb_typeof(target_snapshot) = 'object') OR
                    (operation = 'clear' AND product_external_id IS NULL AND variant_external_id IS NULL AND target_snapshot IS NULL)
                )
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_ms_mapping_revision ON moysklad_listing_mapping_revisions (company_id, connection_id, listing_id, revision)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ms_mapping_request ON moysklad_listing_mapping_revisions (company_id, request_id)');
        $this->addSql('CREATE INDEX idx_ms_mapping_product ON moysklad_listing_mapping_revisions (company_id, connection_id, product_external_id) WHERE product_external_id IS NOT NULL');
        $this->addSql('CREATE INDEX idx_ms_mapping_variant ON moysklad_listing_mapping_revisions (company_id, connection_id, variant_external_id) WHERE variant_external_id IS NOT NULL');
        $this->addSql('ALTER TABLE moysklad_listing_mapping_revisions ADD CONSTRAINT fk_ms_mapping_connection FOREIGN KEY (company_id, connection_id) REFERENCES moysklad_connections (company_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE moysklad_listing_mapping_revisions ADD CONSTRAINT fk_ms_mapping_product FOREIGN KEY (company_id, connection_id, product_external_id) REFERENCES moysklad_products (company_id, connection_id, external_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE moysklad_listing_mapping_revisions ADD CONSTRAINT fk_ms_mapping_variant FOREIGN KEY (company_id, connection_id, variant_external_id) REFERENCES moysklad_variants (company_id, connection_id, external_id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((bool) $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM moysklad_listing_mapping_revisions)'), 'Mapping history is not empty; use a forward migration.');
        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                LOCK TABLE moysklad_listing_mapping_revisions IN ACCESS EXCLUSIVE MODE;
                IF EXISTS (SELECT 1 FROM moysklad_listing_mapping_revisions) THEN
                    RAISE EXCEPTION 'Mapping history is not empty; use a forward migration.';
                END IF;
                DROP TABLE moysklad_listing_mapping_revisions;
            END $$
            SQL);
    }
}
