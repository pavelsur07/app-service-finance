<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251127100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add document status enum and initialize existing documents as active';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE documents ADD status VARCHAR(32) DEFAULT 'ACTIVE' NOT NULL");
        $this->addSql("UPDATE documents SET status = 'ACTIVE' WHERE status IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documents DROP status');
    }
}
