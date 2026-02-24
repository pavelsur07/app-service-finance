<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable cost_price column to marketplace_sales';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_sales')) {
            return;
        }

        $table = $schema->getTable('marketplace_sales');

        if (!$table->hasColumn('cost_price')) {
            $this->addSql('ALTER TABLE marketplace_sales ADD cost_price NUMERIC(10, 2) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_sales')) {
            return;
        }

        $table = $schema->getTable('marketplace_sales');

        if ($table->hasColumn('cost_price')) {
            $this->addSql('ALTER TABLE marketplace_sales DROP cost_price');
        }
    }
}
