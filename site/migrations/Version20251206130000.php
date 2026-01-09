<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251206130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sign_multiplier to wildberries_report_detail_mappings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE wildberries_report_detail_mappings ADD sign_multiplier SMALLINT NOT NULL DEFAULT 1");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE wildberries_report_detail_mappings DROP COLUMN sign_multiplier");
    }
}
