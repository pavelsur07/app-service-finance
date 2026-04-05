<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make marketplace_raw_processing_step_runs.started_at nullable (set in markRunning, not on construction)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_raw_processing_step_runs
                ALTER COLUMN started_at DROP NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE marketplace_raw_processing_step_runs
               SET started_at = created_at
             WHERE started_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_raw_processing_step_runs
                ALTER COLUMN started_at SET NOT NULL
        SQL);
    }
}
