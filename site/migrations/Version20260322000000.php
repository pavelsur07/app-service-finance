<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align marketplace_returns schema with MarketplaceReturn entity: add required listing relation';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_returns')) {
            return;
        }

        $table = $schema->getTable('marketplace_returns');

        if (!$table->hasColumn('listing_id')) {
            $this->addSql('ALTER TABLE marketplace_returns ADD listing_id UUID DEFAULT NULL');
        }

        if ($schema->hasTable('marketplace_sales')) {
            $this->addSql(<<<'SQL'
                UPDATE marketplace_returns mr
                SET listing_id = ms.listing_id
                FROM marketplace_sales ms
                WHERE mr.sale_id = ms.id
                  AND mr.listing_id IS NULL
            SQL);
        }

        if ($schema->hasTable('marketplace_listings')) {
            $this->addSql(<<<'SQL'
                UPDATE marketplace_returns mr
                SET listing_id = ml.id
                FROM marketplace_listings ml
                WHERE mr.listing_id IS NULL
                  AND mr.company_id = ml.company_id
                  AND mr.marketplace = ml.marketplace
                  AND mr.product_id IS NOT NULL
                  AND mr.product_id = ml.product_id
            SQL);

            $this->addSql('DELETE FROM marketplace_returns WHERE listing_id IS NULL');

            $this->addSql('ALTER TABLE marketplace_returns ALTER COLUMN listing_id SET NOT NULL');
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_marketplace_returns_listing ON marketplace_returns (listing_id)');

            $this->addSql(<<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM pg_constraint
                        WHERE conname = 'fk_marketplace_returns_listing'
                    ) THEN
                        ALTER TABLE marketplace_returns
                            ADD CONSTRAINT fk_marketplace_returns_listing
                            FOREIGN KEY (listing_id)
                            REFERENCES marketplace_listings (id)
                            ON DELETE RESTRICT
                            NOT DEFERRABLE INITIALLY IMMEDIATE;
                    END IF;
                END $$;
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_returns')) {
            return;
        }

        $table = $schema->getTable('marketplace_returns');

        $this->addSql('ALTER TABLE marketplace_returns DROP CONSTRAINT IF EXISTS fk_marketplace_returns_listing');
        $this->addSql('DROP INDEX IF EXISTS idx_marketplace_returns_listing');

        if ($table->hasColumn('listing_id')) {
            $this->addSql('ALTER TABLE marketplace_returns DROP COLUMN listing_id');
        }
    }
}
