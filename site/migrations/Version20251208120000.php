<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251208120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow multiple WB mappings for the same source field by dropping unique constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings DROP INDEX uniq_wb_mapping_company_oper_doc_source');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings ADD CONSTRAINT uniq_wb_mapping_company_oper_doc_source UNIQUE (company_id, supplier_oper_name, doc_type_name, source_field)');
    }
}
