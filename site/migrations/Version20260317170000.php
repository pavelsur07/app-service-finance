<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create deal adjustments table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deal_adjustments (id UUID NOT NULL, deal_id UUID NOT NULL, type VARCHAR(32) NOT NULL, recognized_at DATE NOT NULL, amount NUMERIC(18, 2) NOT NULL, comment VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_deal_adjustment_deal ON deal_adjustments (deal_id)');
        $this->addSql('CREATE INDEX idx_deal_adjustment_recognized_at ON deal_adjustments (recognized_at)');
        $this->addSql('ALTER TABLE deal_adjustments ADD CONSTRAINT FK_DEAL_ADJUSTMENTS_DEAL FOREIGN KEY (deal_id) REFERENCES deals (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deal_adjustments DROP CONSTRAINT FK_DEAL_ADJUSTMENTS_DEAL');
        $this->addSql('DROP TABLE deal_adjustments');
    }
}
