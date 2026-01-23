<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260214150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pnl_document_id to wildberries_commissioner_xlsx_reports';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wildberries_commissioner_xlsx_reports ADD pnl_document_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wildberries_commissioner_xlsx_reports DROP pnl_document_id');
    }
}
