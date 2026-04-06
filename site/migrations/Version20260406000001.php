<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceCost: make category_id nullable, set ON DELETE SET NULL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_costs DROP CONSTRAINT IF EXISTS FK_COST_CATEGORY');
        $this->addSql('ALTER TABLE marketplace_costs ALTER COLUMN category_id DROP NOT NULL');

        // Null out orphaned category_id references before creating the new FK
        $this->addSql(<<<'SQL'
            UPDATE marketplace_costs
            SET category_id = NULL
            WHERE category_id IS NOT NULL
              AND category_id NOT IN (SELECT id FROM marketplace_cost_categories)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_costs
                ADD CONSTRAINT FK_COST_CATEGORY
                FOREIGN KEY (category_id)
                REFERENCES marketplace_cost_categories (id)
                ON DELETE SET NULL
                NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_costs DROP CONSTRAINT IF EXISTS FK_COST_CATEGORY');

        // Delete orphaned costs where category was removed via ON DELETE SET NULL
        $this->addSql('DELETE FROM marketplace_costs WHERE category_id IS NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_costs
                ADD CONSTRAINT FK_COST_CATEGORY
                FOREIGN KEY (category_id)
                REFERENCES marketplace_cost_categories (id)
                ON DELETE RESTRICT
                NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        // Delete orphaned costs where category was removed via ON DELETE SET NULL
        $this->addSql('DELETE FROM marketplace_costs WHERE category_id IS NULL');
        $this->addSql('ALTER TABLE marketplace_costs ALTER COLUMN category_id SET NOT NULL');
    }
}
