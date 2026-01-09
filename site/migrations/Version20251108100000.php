<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251108100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add import_id column to wildberries_report_details table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE wildberries_report_details ADD COLUMN import_id UUID DEFAULT '00000000-0000-0000-0000-000000000000' NOT NULL");
        $this->addSql('CREATE INDEX idx_wb_report_detail_import_id ON wildberries_report_details (import_id)');
        $this->addSql("ALTER TABLE wildberries_report_details ALTER COLUMN import_id DROP DEFAULT");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_wb_report_detail_import_id');
        $this->addSql('ALTER TABLE wildberries_report_details DROP COLUMN import_id');
    }
}
