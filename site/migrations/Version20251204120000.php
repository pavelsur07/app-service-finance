<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251204120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update unique constraint for wildberries_report_detail_mappings: drop site_country from key';
    }

    public function up(Schema $schema): void
    {
        // Удаляем старый unique-индекс (если есть) с site_country
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings DROP CONSTRAINT IF EXISTS uniq_wb_mapping_company_oper_doc_country_source');
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings DROP CONSTRAINT IF EXISTS uniq_wb_mapping_company_oper_doc_country');

        // Удаляем потенциальные дубликаты, которые появились из-за того, что в ключе больше нет site_country
        $this->addSql(<<<'SQL'
DELETE FROM wildberries_report_detail_mappings wrdm
USING (
    SELECT id,
           ROW_NUMBER() OVER (
               PARTITION BY company_id, supplier_oper_name, doc_type_name, source_field
               ORDER BY updated_at DESC, created_at DESC, id DESC
           ) AS rn
    FROM wildberries_report_detail_mappings
) dup
WHERE wrdm.id = dup.id
  AND dup.rn > 1;
SQL);

        // Добавляем новый unique-индекс без site_country
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings ADD CONSTRAINT uniq_wb_mapping_company_oper_doc_source UNIQUE (company_id, supplier_oper_name, doc_type_name, source_field)');
    }

    public function down(Schema $schema): void
    {
        // Откатываем: удаляем новый индекс и возвращаем старый с site_country
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings DROP CONSTRAINT IF EXISTS uniq_wb_mapping_company_oper_doc_source');
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings ADD CONSTRAINT uniq_wb_mapping_company_oper_doc_country_source UNIQUE (company_id, supplier_oper_name, doc_type_name, site_country, source_field)');
    }
}
