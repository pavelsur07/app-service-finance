<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251206120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add note field to wildberries_report_detail_mappings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings ADD note VARCHAR(1024) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wildberries_report_detail_mappings DROP COLUMN note');
    }
}
