<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251003120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add flow column to pl_categories table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pl_categories ADD flow VARCHAR(16) NOT NULL DEFAULT 'NONE'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pl_categories DROP COLUMN flow');
    }
}
