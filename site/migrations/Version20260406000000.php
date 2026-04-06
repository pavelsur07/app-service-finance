<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceRawDocument: add pipeline processing status fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_raw_documents
                ADD COLUMN processing_status  VARCHAR(20)                       DEFAULT NULL,
                ADD COLUMN processed_at       TIMESTAMP(0) WITHOUT TIME ZONE    DEFAULT NULL,
                ADD COLUMN failed_steps       JSONB                              DEFAULT NULL,
                ADD COLUMN succeeded_steps    JSONB                               DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_raw_documents
                DROP COLUMN processing_status,
                DROP COLUMN processed_at,
                DROP COLUMN failed_steps,
                DROP COLUMN succeeded_steps
        SQL);
    }
}
