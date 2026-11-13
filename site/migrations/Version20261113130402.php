<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113130402 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SourceFacts: журнал пачек, ревизии периодов и версии записей источника (sf_*)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sf_batch (id UUID NOT NULL, service VARCHAR(64) NOT NULL, batch_id VARCHAR(255) NOT NULL, company_id UUID DEFAULT NULL, connection_ref VARCHAR(64) NOT NULL, resource_type VARCHAR(128) NOT NULL, period_key VARCHAR(32) NOT NULL, revision INT NOT NULL, content_hash VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, reason VARCHAR(32) DEFAULT NULL, details TEXT DEFAULT NULL, attempts INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_sf_batch_status ON sf_batch (status)');
        $this->addSql('CREATE UNIQUE INDEX uq_sf_batch_service_batch ON sf_batch (service, batch_id)');
        $this->addSql('CREATE TABLE sf_fact (id UUID NOT NULL, company_id UUID NOT NULL, connection_ref VARCHAR(64) NOT NULL, resource_type VARCHAR(128) NOT NULL, period_key VARCHAR(32) NOT NULL, source_key VARCHAR(255) NOT NULL, payload_hash VARCHAR(64) NOT NULL, payload JSONB NOT NULL, first_revision INT NOT NULL, last_seen_revision INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_sf_fact_version ON sf_fact (company_id, connection_ref, resource_type, period_key, source_key, payload_hash)');
        $this->addSql('CREATE TABLE sf_period (id UUID NOT NULL, company_id UUID NOT NULL, connection_ref VARCHAR(64) NOT NULL, resource_type VARCHAR(128) NOT NULL, period_key VARCHAR(32) NOT NULL, current_revision INT NOT NULL, fact_count INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_sf_period ON sf_period (company_id, connection_ref, resource_type, period_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sf_batch');
        $this->addSql('DROP TABLE sf_fact');
        $this->addSql('DROP TABLE sf_period');
    }
}
