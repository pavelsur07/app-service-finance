<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260214130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add meta column to Wildberries import log';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('wildberries_import_log')) {
            return;
        }

        $table = $schema->getTable('wildberries_import_log');
        if ($table->hasColumn('meta')) {
            return;
        }

        $this->addSql('ALTER TABLE wildberries_import_log ADD meta JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('wildberries_import_log')) {
            return;
        }

        $table = $schema->getTable('wildberries_import_log');
        if (!$table->hasColumn('meta')) {
            return;
        }

        $this->addSql('ALTER TABLE wildberries_import_log DROP meta');
    }
}
