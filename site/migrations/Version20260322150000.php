<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align marketplace_costs table with MarketplaceCost entity';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_costs')) {
            return;
        }

        $table = $schema->getTable('marketplace_costs');

        if (!$table->hasColumn('raw_document_id')) {
            $this->addSql('ALTER TABLE marketplace_costs ADD raw_document_id UUID DEFAULT NULL');
        }

        if (!$table->hasColumn('document_id')) {
            $this->addSql('ALTER TABLE marketplace_costs ADD document_id UUID DEFAULT NULL');
        }

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_cost_document ON marketplace_costs (document_id)');

        if ($schema->hasTable('documents')) {
            $this->addSql(<<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM pg_constraint
                        WHERE conname = 'fk_cost_document'
                    ) THEN
                        ALTER TABLE marketplace_costs
                            ADD CONSTRAINT fk_cost_document
                            FOREIGN KEY (document_id)
                            REFERENCES documents (id)
                            ON DELETE SET NULL
                            NOT DEFERRABLE INITIALLY IMMEDIATE;
                    END IF;
                END $$;
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_costs')) {
            return;
        }

        $table = $schema->getTable('marketplace_costs');

        $this->addSql('ALTER TABLE marketplace_costs DROP CONSTRAINT IF EXISTS fk_cost_document');
        $this->addSql('DROP INDEX IF EXISTS idx_cost_document');

        if ($table->hasColumn('document_id')) {
            $this->addSql('ALTER TABLE marketplace_costs DROP COLUMN document_id');
        }

        if ($table->hasColumn('raw_document_id')) {
            $this->addSql('ALTER TABLE marketplace_costs DROP COLUMN raw_document_id');
        }
    }
}
