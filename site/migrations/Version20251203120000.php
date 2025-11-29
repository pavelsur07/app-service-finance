<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251203120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update unique constraint for wildberries_report_detail_mappings to include source_field';
    }

    public function up(Schema $schema): void
    {
        // Удаляем старый unique-индекс на 4 колонки
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings DROP CONSTRAINT IF EXISTS uniq_wb_mapping_company_oper_doc_country');

        // Добавляем новый unique-индекс на 5 колонок, включая source_field
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings ADD CONSTRAINT uniq_wb_mapping_company_oper_doc_country_source UNIQUE (company_id, supplier_oper_name, doc_type_name, site_country, source_field)');
    }

    public function down(Schema $schema): void
    {
        // Откатываем: удаляем новый индекс и возвращаем старый
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings DROP CONSTRAINT IF EXISTS uniq_wb_mapping_company_oper_doc_country_source');
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings ADD CONSTRAINT uniq_wb_mapping_company_oper_doc_country UNIQUE (company_id, supplier_oper_name, doc_type_name, site_country)');
    }
}
