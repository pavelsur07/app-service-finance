<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deduplicate inventory locations and stock snapshots; enforce location uniqueness by company, source, external ID';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_inventory_locations_external');

        $this->addSql(<<<'SQL'
            WITH ranked_locations AS (
                SELECT
                    id,
                    company_id,
                    external_system,
                    external_id,
                    ROW_NUMBER() OVER (
                        PARTITION BY company_id, external_system, external_id
                        ORDER BY created_at ASC, id ASC
                    ) AS rn,
                    FIRST_VALUE(id) OVER (
                        PARTITION BY company_id, external_system, external_id
                        ORDER BY created_at ASC, id ASC
                    ) AS canonical_id
                FROM inventory_locations
                WHERE external_id IS NOT NULL
            )
            UPDATE inventory_stock_snapshots s
            SET location_id = rl.canonical_id
            FROM ranked_locations rl
            WHERE s.location_id = rl.id
              AND rl.rn > 1
        SQL);

        $this->addSql(<<<'SQL'
            WITH ranked_snapshots AS (
                SELECT
                    id,
                    ROW_NUMBER() OVER (
                        PARTITION BY company_id, snapshot_session_id, snapshot_date, source, source_sku, fulfillment_type, status
                        ORDER BY created_at DESC, id DESC
                    ) AS rn
                FROM inventory_stock_snapshots
            )
            DELETE FROM inventory_stock_snapshots s
            USING ranked_snapshots rs
            WHERE s.id = rs.id
              AND rs.rn > 1
        SQL);

        $this->addSql(<<<'SQL'
            WITH ranked_locations AS (
                SELECT
                    id,
                    ROW_NUMBER() OVER (
                        PARTITION BY company_id, external_system, external_id
                        ORDER BY created_at ASC, id ASC
                    ) AS rn
                FROM inventory_locations
                WHERE external_id IS NOT NULL
            )
            DELETE FROM inventory_locations l
            USING ranked_locations rl
            WHERE l.id = rl.id
              AND rl.rn > 1
        SQL);

        $this->addSql("CREATE UNIQUE INDEX uniq_inventory_location_company_external ON inventory_locations (company_id, external_system, external_id) WHERE external_id IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_inventory_location_company_external');
        $this->addSql("CREATE UNIQUE INDEX uniq_inventory_locations_external ON inventory_locations (company_id, external_system, external_id) WHERE external_id IS NOT NULL");
    }
}
