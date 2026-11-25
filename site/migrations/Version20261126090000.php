<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Категории листингов: правила по категории площадки';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_listing_category_rules (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                source_key VARCHAR(64) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                category_id UUID NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_listing_category_rule_key ON marketplace_listing_category_rules (company_id, source_key)');
        $this->addSql('CREATE INDEX IDX_380540C512469DE2 ON marketplace_listing_category_rules (category_id)');
        $this->addSql('ALTER TABLE marketplace_listing_category_rules ADD CONSTRAINT FK_380540C512469DE2 FOREIGN KEY (category_id) REFERENCES marketplace_listing_categories (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_listing_category_rules');
    }
}
