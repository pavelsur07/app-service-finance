<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260321130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure marketplace_cost_categories table exists with required structure';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_cost_categories')) {
            $this->addSql('CREATE TABLE marketplace_cost_categories (id UUID NOT NULL, company_id UUID NOT NULL, marketplace VARCHAR(255) NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(50) NOT NULL, pl_category_id UUID DEFAULT NULL, description TEXT DEFAULT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX idx_cost_category_company ON marketplace_cost_categories (company_id)');
            $this->addSql('CREATE UNIQUE INDEX uniq_company_marketplace_code ON marketplace_cost_categories (company_id, marketplace, code)');

            if ($schema->hasTable('companies')) {
                $this->addSql('ALTER TABLE marketplace_cost_categories ADD CONSTRAINT FK_COST_CAT_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
            }

            if ($schema->hasTable('pl_categories')) {
                $this->addSql('ALTER TABLE marketplace_cost_categories ADD CONSTRAINT FK_COST_CAT_PL_CATEGORY FOREIGN KEY (pl_category_id) REFERENCES pl_categories (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            }

            return;
        }

        $table = $schema->getTable('marketplace_cost_categories');

        if (!$table->hasColumn('marketplace')) {
            $this->addSql("ALTER TABLE marketplace_cost_categories ADD COLUMN marketplace VARCHAR(255) DEFAULT 'wildberries'");
            $this->addSql("UPDATE marketplace_cost_categories SET marketplace = 'wildberries' WHERE marketplace IS NULL");
            $this->addSql('ALTER TABLE marketplace_cost_categories ALTER COLUMN marketplace SET NOT NULL');
            $this->addSql('ALTER TABLE marketplace_cost_categories ALTER COLUMN marketplace DROP DEFAULT');
        }

        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_company_marketplace_code ON marketplace_cost_categories (company_id, marketplace, code)');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_cost_categories')) {
            return;
        }

        $this->addSql('DROP TABLE marketplace_cost_categories');
    }
}
