<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create deals table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE deals (id UUID NOT NULL, company_id UUID NOT NULL, number VARCHAR(64) NOT NULL, title VARCHAR(255) DEFAULT NULL, type VARCHAR(32) NOT NULL, channel VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'draft', recognized_at DATE NOT NULL, occurred_at DATE DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, total_amount NUMERIC(18, 2) NOT NULL DEFAULT '0', total_direct_cost NUMERIC(18, 2) NOT NULL DEFAULT '0', created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_deal_company_number ON deals (company_id, number)');
        $this->addSql('CREATE INDEX idx_deal_company_recognized_at ON deals (company_id, recognized_at)');
        $this->addSql('CREATE INDEX idx_deal_company_status ON deals (company_id, status)');
        $this->addSql('COMMENT ON COLUMN deals.recognized_at IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN deals.occurred_at IS \'(DC2Type:date_immutable)\'');
        $this->addSql("COMMENT ON COLUMN deals.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN deals.updated_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('ALTER TABLE deals ADD CONSTRAINT FK_DEALS_COMPANY FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deals DROP CONSTRAINT FK_DEALS_COMPANY');
        $this->addSql('DROP TABLE deals');
    }
}
