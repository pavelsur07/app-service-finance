<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create deal items table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deal_items (id UUID NOT NULL, deal_id UUID NOT NULL, name VARCHAR(255) NOT NULL, kind VARCHAR(255) NOT NULL, unit VARCHAR(32) DEFAULT NULL, qty NUMERIC(18, 2) NOT NULL, price NUMERIC(18, 2) NOT NULL, amount NUMERIC(18, 2) NOT NULL, line_index INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_deal_item_deal ON deal_items (deal_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deal_item_deal_line_index ON deal_items (deal_id, line_index)');
        $this->addSql('ALTER TABLE deal_items ADD CONSTRAINT FK_DEAL_ITEMS_DEAL FOREIGN KEY (deal_id) REFERENCES deals (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deal_items DROP CONSTRAINT FK_DEAL_ITEMS_DEAL');
        $this->addSql('DROP TABLE deal_items');
    }
}
