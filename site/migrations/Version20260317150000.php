<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create deal charge types table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deal_charge_types (id UUID NOT NULL, company_id UUID NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_deal_charge_type_company_code ON deal_charge_types (company_id, code)');
        $this->addSql('CREATE INDEX idx_deal_charge_type_company_active ON deal_charge_types (company_id, is_active)');
        $this->addSql('ALTER TABLE deal_charge_types ADD CONSTRAINT fk_deal_charge_type_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deal_charge_types DROP CONSTRAINT fk_deal_charge_type_company');
        $this->addSql('DROP TABLE deal_charge_types');
    }
}
