<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Категории листингов: справочник компании и category_id у листинга';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_listing_categories (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                name VARCHAR(50) NOT NULL,
                slug VARCHAR(50) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_listing_category_company_slug ON marketplace_listing_categories (company_id, slug)');
        $this->addSql('ALTER TABLE marketplace_listings ADD category_source VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_listings ADD category_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_listings ADD CONSTRAINT FK_8E9E631212469DE2 FOREIGN KEY (category_id) REFERENCES marketplace_listing_categories (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_marketplace_listing_category ON marketplace_listings (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_listings DROP CONSTRAINT FK_8E9E631212469DE2');
        $this->addSql('DROP INDEX idx_marketplace_listing_category');
        $this->addSql('ALTER TABLE marketplace_listings DROP category_id');
        $this->addSql('ALTER TABLE marketplace_listings DROP category_source');
        $this->addSql('DROP TABLE marketplace_listing_categories');
    }
}
