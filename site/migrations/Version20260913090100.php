<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create company-scoped API keys; persist only secret hashes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_keys (id UUID NOT NULL, company_id UUID NOT NULL, name VARCHAR(255) NOT NULL, public_identifier VARCHAR(32) NOT NULL, secret_hash VARCHAR(64) NOT NULL, created_by UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, last_used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_api_keys_identifier ON api_keys (public_identifier)');
        $this->addSql('CREATE INDEX idx_api_keys_company_created ON api_keys (company_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('API key revocations and credentials must be retained; use a forward fix.');
    }
}
