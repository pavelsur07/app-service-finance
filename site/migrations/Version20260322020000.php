<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align marketplace_costs schema with MarketplaceCost entity: add listing relation';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_costs')) {
            return;
        }

        $table = $schema->getTable('marketplace_costs');

        if (!$table->hasColumn('listing_id')) {
            $this->addSql('ALTER TABLE marketplace_costs ADD listing_id UUID DEFAULT NULL');
        }

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_cost_listing ON marketplace_costs (listing_id)');

        if ($schema->hasTable('marketplace_listings')) {
            $this->addSql(<<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM pg_constraint
                        WHERE conname = 'FK_COST_LISTING'
                    ) THEN
                        ALTER TABLE marketplace_costs
                            ADD CONSTRAINT FK_COST_LISTING
                            FOREIGN KEY (listing_id)
                            REFERENCES marketplace_listings (id)
                            ON DELETE SET NULL
                            NOT DEFERRABLE INITIALLY IMMEDIATE;
                    END IF;
                END $$;
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_costs')) {
            return;
        }

        $table = $schema->getTable('marketplace_costs');

        $this->addSql('ALTER TABLE marketplace_costs DROP CONSTRAINT IF EXISTS FK_COST_LISTING');
        $this->addSql('DROP INDEX IF EXISTS idx_cost_listing');

        if ($table->hasColumn('listing_id')) {
            $this->addSql('ALTER TABLE marketplace_costs DROP COLUMN listing_id');
        }
    }
}
