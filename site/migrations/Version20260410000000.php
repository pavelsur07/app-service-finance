<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260410000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create marketplace_reconciliation_sessions table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_reconciliation_sessions (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                marketplace VARCHAR(32) NOT NULL,
                period_from DATE NOT NULL,
                period_to DATE NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                stored_file_path VARCHAR(512) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                result_json TEXT DEFAULT NULL,
                error_message VARCHAR(1024) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql("COMMENT ON COLUMN marketplace_reconciliation_sessions.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_reconciliation_sessions.completed_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_reconciliation_sessions.period_from IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_reconciliation_sessions.period_to IS '(DC2Type:date_immutable)'");

        $this->addSql('CREATE INDEX idx_recon_session_lookup ON marketplace_reconciliation_sessions (company_id, marketplace, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_reconciliation_sessions');
    }
}
