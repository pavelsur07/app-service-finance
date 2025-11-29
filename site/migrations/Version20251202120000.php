<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251202120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create wildberries_report_detail_mappings table for mapping report fields to PL categories';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE wildberries_report_detail_mappings (id UUID NOT NULL, company_id UUID NOT NULL, supplier_oper_name VARCHAR(255) NOT NULL, doc_type_name VARCHAR(255) DEFAULT NULL, site_country VARCHAR(255) DEFAULT NULL, source_field VARCHAR(64) NOT NULL, pl_category_id UUID NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_wb_report_detail_mappings_company ON wildberries_report_detail_mappings (company_id)');
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings ADD CONSTRAINT FK_WB_MAPPING_COMPANY FOREIGN KEY (company_id) REFERENCES "companies" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings ADD CONSTRAINT FK_WB_MAPPING_PL_CATEGORY FOREIGN KEY (pl_category_id) REFERENCES pl_categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings ADD CONSTRAINT uniq_wb_mapping_company_oper_doc_country UNIQUE (company_id, supplier_oper_name, doc_type_name, site_country)');
        $this->addSql("COMMENT ON COLUMN wildberries_report_detail_mappings.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN wildberries_report_detail_mappings.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings DROP CONSTRAINT FK_WB_MAPPING_COMPANY');
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings DROP CONSTRAINT FK_WB_MAPPING_PL_CATEGORY');
        $this->addSql('DROP TABLE wildberries_report_detail_mappings');
    }
}
