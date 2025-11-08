<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251115090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create daily Wildberries RNP aggregates table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE wildberries_rnp_daily (id UUID NOT NULL, company_id UUID NOT NULL, date DATE NOT NULL, sku VARCHAR(128) NOT NULL, category VARCHAR(191) DEFAULT NULL, brand VARCHAR(191) DEFAULT NULL, orders_count_spp INT NOT NULL DEFAULT 0, orders_sum_spp_minor INT NOT NULL DEFAULT 0, sales_count_spp INT NOT NULL DEFAULT 0, sales_sum_spp_minor INT NOT NULL DEFAULT 0, ad_cost_sum_minor INT NOT NULL DEFAULT 0, buyout_rate NUMERIC(5, 2) NOT NULL DEFAULT 0, cogs_sum_spp_minor INT NOT NULL DEFAULT 0, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN wildberries_rnp_daily.date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX uniq_wb_rnp_company_date_sku ON wildberries_rnp_daily (company_id, date, sku)');
        $this->addSql('CREATE INDEX idx_wb_rnp_company_date ON wildberries_rnp_daily (company_id, date)');
        $this->addSql('CREATE INDEX idx_wb_rnp_company_sku ON wildberries_rnp_daily (company_id, sku)');
        $this->addSql('CREATE INDEX idx_wb_rnp_company_brand ON wildberries_rnp_daily (company_id, brand)');
        $this->addSql('CREATE INDEX idx_wb_rnp_company_category ON wildberries_rnp_daily (company_id, category)');
        $this->addSql('ALTER TABLE wildberries_rnp_daily ADD CONSTRAINT fk_wb_rnp_company FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wildberries_rnp_daily DROP CONSTRAINT fk_wb_rnp_company');
        $this->addSql('DROP TABLE wildberries_rnp_daily');
    }
}
