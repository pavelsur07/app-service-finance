<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist user MCP OAuth consent, connections and revocable credentials.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mcp_oauth_requests (id_hash VARCHAR(64) NOT NULL, payload TEXT NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id_hash))');
        $this->addSql('CREATE INDEX idx_mcp_oauth_requests_expiry ON mcp_oauth_requests (expires_at)');
        $this->addSql('CREATE TABLE mcp_oauth_connections (id UUID NOT NULL, company_id UUID NOT NULL, user_id UUID NOT NULL, client_id VARCHAR(64) NOT NULL, resource TEXT NOT NULL, scopes TEXT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_mcp_oauth_connections_company ON mcp_oauth_connections (company_id)');
        $this->addSql('CREATE INDEX idx_mcp_oauth_connections_user ON mcp_oauth_connections (user_id, expires_at)');
        $this->addSql('CREATE TABLE mcp_oauth_credentials (id_hash VARCHAR(64) NOT NULL, kind VARCHAR(8) NOT NULL, connection_id UUID NOT NULL, wire_hash VARCHAR(64) DEFAULT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id_hash))');
        $this->addSql('CREATE INDEX idx_mcp_oauth_credentials_connection ON mcp_oauth_credentials (connection_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_mcp_oauth_credentials_wire ON mcp_oauth_credentials (wire_hash)');
        $this->addSql('ALTER TABLE mcp_oauth_connections ADD CONSTRAINT fk_mcp_oauth_connections_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mcp_oauth_connections ADD CONSTRAINT fk_mcp_oauth_connections_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mcp_oauth_credentials ADD CONSTRAINT fk_mcp_oauth_credentials_connection FOREIGN KEY (connection_id) REFERENCES mcp_oauth_connections (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mcp_oauth_credentials');
        $this->addSql('DROP TABLE mcp_oauth_connections');
        $this->addSql('DROP TABLE mcp_oauth_requests');
    }
}
