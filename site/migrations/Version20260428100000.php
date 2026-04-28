<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inventory: create inventory_locations table with partial unique index by external id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_locations (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                type VARCHAR(50) NOT NULL,
                external_system VARCHAR(50) NOT NULL,
                external_id VARCHAR(255) DEFAULT NULL,
                code VARCHAR(100) NOT NULL,
                name VARCHAR(255) NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                metadata JSONB DEFAULT NULL,
                created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_inventory_locations_external
            ON inventory_locations (company_id, external_system, external_id)
            WHERE external_id IS NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_inventory_locations_company_type_active
            ON inventory_locations (company_id, type, is_active)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_inventory_locations_company_external_system
            ON inventory_locations (company_id, external_system)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_inventory_locations_external');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_locations_company_type_active');
        $this->addSql('DROP INDEX IF EXISTS idx_inventory_locations_company_external_system');
        $this->addSql('DROP TABLE IF EXISTS inventory_locations');
    }
}
