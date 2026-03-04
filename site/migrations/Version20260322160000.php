<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align documents table with Document entity by adding source and stream columns';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('documents')) {
            return;
        }

        $table = $schema->getTable('documents');

        if (!$table->hasColumn('source')) {
            $this->addSql('ALTER TABLE documents ADD source VARCHAR(32) DEFAULT NULL');
        }

        if (!$table->hasColumn('stream')) {
            $this->addSql('ALTER TABLE documents ADD stream VARCHAR(32) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('documents')) {
            return;
        }

        $table = $schema->getTable('documents');

        if ($table->hasColumn('stream')) {
            $this->addSql('ALTER TABLE documents DROP COLUMN stream');
        }

        if ($table->hasColumn('source')) {
            $this->addSql('ALTER TABLE documents DROP COLUMN source');
        }
    }
}
