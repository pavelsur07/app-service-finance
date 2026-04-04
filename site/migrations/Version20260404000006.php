<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Document: add createdWithViolation field';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE documents ADD created_with_violation BOOLEAN NOT NULL DEFAULT false
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE documents DROP COLUMN created_with_violation
        SQL);
    }
}
