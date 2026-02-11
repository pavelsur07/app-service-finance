<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260211070759 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create marketplace_connections table for marketplace API integrations';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('marketplace_connections')) {
            return;
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_connections (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                marketplace VARCHAR(255) NOT NULL,
                api_key TEXT NOT NULL,
                is_active BOOLEAN NOT NULL,
                last_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_successful_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_sync_error TEXT DEFAULT NULL,
                settings JSON DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT pk_marketplace_connections PRIMARY KEY (id),
                CONSTRAINT fk_marketplace_connections_company FOREIGN KEY (company_id)
                    REFERENCES companies (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);

        $this->addSql('CREATE INDEX idx_connection_company ON marketplace_connections (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_marketplace ON marketplace_connections (company_id, marketplace)');

        $this->addSql("COMMENT ON COLUMN marketplace_connections.last_sync_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_connections.last_successful_sync_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_connections.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_connections.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_connections')) {
            return;
        }

        $this->addSql('DROP TABLE marketplace_connections');
    }
}
