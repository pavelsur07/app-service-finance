<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250920000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create report_api_key table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE report_api_key (id UUID NOT NULL, company_id UUID NOT NULL, key_prefix VARCHAR(16) NOT NULL, key_hash TEXT NOT NULL, scopes VARCHAR(255) DEFAULT \'reports:read\' NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_report_api_key_company ON report_api_key (company_id)');
        $this->addSql('CREATE INDEX idx_report_api_key_key_prefix ON report_api_key (key_prefix)');
        $this->addSql('CREATE INDEX idx_report_api_key_is_active ON report_api_key (is_active)');
        $this->addSql('ALTER TABLE report_api_key ADD CONSTRAINT fk_report_api_key_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE report_api_key DROP CONSTRAINT fk_report_api_key_company');
        $this->addSql('DROP TABLE report_api_key');
    }
}
