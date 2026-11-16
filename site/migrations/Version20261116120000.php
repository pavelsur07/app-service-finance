<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nullable MoySklad barcode snapshots and company/connection/marketplace mapping settings; preserves assignments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE moysklad_products ADD barcodes JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE moysklad_variants ADD barcodes JSONB DEFAULT NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE moysklad_mapping_settings (
                company_id UUID NOT NULL, connection_id UUID NOT NULL, marketplace VARCHAR(20) NOT NULL,
                wb_trailing_w BOOLEAN NOT NULL DEFAULT FALSE, version INT NOT NULL DEFAULT 0,
                PRIMARY KEY (company_id, connection_id, marketplace),
                CONSTRAINT fk_ms_settings_connection FOREIGN KEY (company_id, connection_id)
                    REFERENCES moysklad_connections (company_id, id) ON DELETE RESTRICT,
                CONSTRAINT chk_ms_settings_marketplace CHECK (marketplace IN ('ozon', 'wildberries')),
                CONSTRAINT chk_ms_settings_preset CHECK (NOT wb_trailing_w OR marketplace = 'wildberries'),
                CONSTRAINT chk_ms_settings_version CHECK (version >= 0)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Imported barcodes and settings must be preserved; use a forward migration.');
    }
}
