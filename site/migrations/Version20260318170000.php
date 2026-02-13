<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add expense_type to pl_categories';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('pl_categories')) {
            return;
        }

        $table = $schema->getTable('pl_categories');

        if (!$table->hasColumn('expense_type')) {
            $this->addSql("ALTER TABLE pl_categories ADD expense_type VARCHAR(20) NOT NULL DEFAULT 'other'");
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('pl_categories')) {
            return;
        }

        $table = $schema->getTable('pl_categories');

        if ($table->hasColumn('expense_type')) {
            $this->addSql('ALTER TABLE pl_categories DROP COLUMN expense_type');
        }
    }
}
