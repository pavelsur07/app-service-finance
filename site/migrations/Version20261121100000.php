<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store MCP payment draft provenance and enforce external identity per connection.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_payment_intake_draft ADD mcp_connection_id UUID DEFAULT NULL, ADD mcp_external_id VARCHAR(128) DEFAULT NULL, ADD confirmed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_intake_mcp_external ON cash_payment_intake_draft (company_id, mcp_connection_id, mcp_external_id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('MCP drafts require provenance for safe confirmation and replay.');
    }
}
