<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace to marketplace_cost_categories and update unique index';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_cost_categories')) {
            return;
        }

        $table = $schema->getTable('marketplace_cost_categories');

        if (!$table->hasColumn('marketplace')) {
            $this->addSql("ALTER TABLE marketplace_cost_categories ADD COLUMN marketplace VARCHAR(255) DEFAULT 'wildberries'");
            $this->addSql("UPDATE marketplace_cost_categories SET marketplace = 'wildberries' WHERE marketplace IS NULL");
            $this->addSql('ALTER TABLE marketplace_cost_categories ALTER COLUMN marketplace SET NOT NULL');
            $this->addSql('ALTER TABLE marketplace_cost_categories ALTER COLUMN marketplace DROP DEFAULT');
        }

        if ($table->hasIndex('uniq_company_code')) {
            $this->addSql('DROP INDEX uniq_company_code');
        }

        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_company_marketplace_code ON marketplace_cost_categories (company_id, marketplace, code)');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_cost_categories')) {
            return;
        }

        $table = $schema->getTable('marketplace_cost_categories');

        $this->addSql('DROP INDEX IF EXISTS uniq_company_marketplace_code');

        if ($table->hasColumn('marketplace')) {
            $this->addSql('ALTER TABLE marketplace_cost_categories DROP COLUMN marketplace');
        }

        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_company_code ON marketplace_cost_categories (company_id, code)');
    }
}
