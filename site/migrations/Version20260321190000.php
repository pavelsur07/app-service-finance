<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260321190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align marketplace_listings constraints with MarketplaceListing entity';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_listings')) {
            return;
        }

        $table = $schema->getTable('marketplace_listings');

        if ($table->hasIndex('uniq_product_marketplace')) {
            $this->addSql('DROP INDEX uniq_product_marketplace');
        }

        if ($table->hasColumn('product_id') && $table->getColumn('product_id')->getNotnull()) {
            $this->addSql('ALTER TABLE marketplace_listings ALTER COLUMN product_id DROP NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_listings')) {
            return;
        }

        $table = $schema->getTable('marketplace_listings');

        if ($table->hasColumn('product_id') && !$table->getColumn('product_id')->getNotnull()) {
            $this->addSql('DELETE FROM marketplace_listings WHERE product_id IS NULL');
            $this->addSql('ALTER TABLE marketplace_listings ALTER COLUMN product_id SET NOT NULL');
        }

        if (!$table->hasIndex('uniq_product_marketplace')) {
            $this->addSql('CREATE UNIQUE INDEX uniq_product_marketplace ON marketplace_listings (product_id, marketplace)');
        }
    }
}
