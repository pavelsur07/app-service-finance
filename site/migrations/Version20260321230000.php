<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260321230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align marketplace_returns schema with MarketplaceReturn entity mapping (nullable product)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_returns')) {
            return;
        }

        $table = $schema->getTable('marketplace_returns');

        if ($table->hasColumn('product_id') && $table->getColumn('product_id')->getNotnull()) {
            $this->addSql('ALTER TABLE marketplace_returns ALTER COLUMN product_id DROP NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_returns')) {
            return;
        }

        $table = $schema->getTable('marketplace_returns');

        if ($table->hasColumn('product_id') && !$table->getColumn('product_id')->getNotnull()) {
            $this->addSql('DELETE FROM marketplace_returns WHERE product_id IS NULL');
            $this->addSql('ALTER TABLE marketplace_returns ALTER COLUMN product_id SET NOT NULL');
        }
    }
}
