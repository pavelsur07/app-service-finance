<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add metadata fields to pl_categories';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pl_categories
    ADD code VARCHAR(64) DEFAULT NULL,
    ADD type VARCHAR(16) NOT NULL DEFAULT 'LEAF_INPUT',
    ADD format VARCHAR(16) NOT NULL DEFAULT 'MONEY',
    ADD weight_in_parent NUMERIC(10,4) NOT NULL DEFAULT 1.0000,
    ADD is_visible BOOLEAN NOT NULL DEFAULT TRUE,
    ADD formula TEXT DEFAULT NULL,
    ADD calc_order INT DEFAULT NULL
");
        $this->addSql("CREATE UNIQUE INDEX IF NOT EXISTS uniq_plcat_company_code ON pl_categories (company_id, code)");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_plcat_company_parent_sort ON pl_categories (company_id, parent_id, sort_order)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DROP INDEX IF EXISTS idx_plcat_company_parent_sort");
        $this->addSql("DROP INDEX IF EXISTS uniq_plcat_company_code");
        $this->addSql("ALTER TABLE pl_categories
    DROP COLUMN code,
    DROP COLUMN type,
    DROP COLUMN format,
    DROP COLUMN weight_in_parent,
    DROP COLUMN is_visible,
    DROP COLUMN formula,
    DROP COLUMN calc_order
");
    }
}
