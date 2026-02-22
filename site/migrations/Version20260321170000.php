<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260321170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce unique product SKU per company';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('products')) {
            return;
        }

        $table = $schema->getTable('products');

        if ($table->hasIndex('idx_company_sku')) {
            $this->addSql('DROP INDEX idx_company_sku');
        }

        if (!$table->hasIndex('uniq_company_sku')) {
            $this->addSql('CREATE UNIQUE INDEX uniq_company_sku ON products (company_id, sku)');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('products')) {
            return;
        }

        $table = $schema->getTable('products');

        if ($table->hasIndex('uniq_company_sku')) {
            $this->addSql('DROP INDEX uniq_company_sku');
        }

        if (!$table->hasIndex('idx_company_sku')) {
            $this->addSql('CREATE INDEX idx_company_sku ON products (company_id, sku)');
        }
    }
}
