<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add minimum_safe_balance to money_account';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('money_account')) {
            return;
        }

        $table = $schema->getTable('money_account');

        if (!$table->hasColumn('minimum_safe_balance')) {
            $this->addSql("ALTER TABLE money_account ADD minimum_safe_balance NUMERIC(14, 2) DEFAULT '0.00' NOT NULL");
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('money_account')) {
            return;
        }

        $table = $schema->getTable('money_account');

        if ($table->hasColumn('minimum_safe_balance')) {
            $this->addSql('ALTER TABLE money_account DROP COLUMN minimum_safe_balance');
        }
    }
}
