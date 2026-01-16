<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tax system field to companies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD COLUMN IF NOT EXISTS tax_system VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies DROP COLUMN IF EXISTS tax_system');
    }
}
