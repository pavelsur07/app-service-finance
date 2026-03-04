<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align marketplace_returns table with MarketplaceReturn entity';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_returns')) {
            return;
        }

        $table = $schema->getTable('marketplace_returns');

        if (!$table->hasColumn('raw_document_id')) {
            $this->addSql('ALTER TABLE marketplace_returns ADD raw_document_id UUID DEFAULT NULL');
        }

        if (!$table->hasColumn('document_id')) {
            $this->addSql('ALTER TABLE marketplace_returns ADD document_id UUID DEFAULT NULL');
        }

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_return_document ON marketplace_returns (document_id)');

        if ($schema->hasTable('documents')) {
            $this->addSql(<<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM pg_constraint
                        WHERE conname = 'fk_return_document'
                    ) THEN
                        ALTER TABLE marketplace_returns
                            ADD CONSTRAINT fk_return_document
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
        if (!$schema->hasTable('marketplace_returns')) {
            return;
        }

        $table = $schema->getTable('marketplace_returns');

        $this->addSql('ALTER TABLE marketplace_returns DROP CONSTRAINT IF EXISTS fk_return_document');
        $this->addSql('DROP INDEX IF EXISTS idx_return_document');

        if ($table->hasColumn('document_id')) {
            $this->addSql('ALTER TABLE marketplace_returns DROP COLUMN document_id');
        }

        if ($table->hasColumn('raw_document_id')) {
            $this->addSql('ALTER TABLE marketplace_returns DROP COLUMN raw_document_id');
        }
    }
}
