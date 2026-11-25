<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Подключения баз 1С и организации одного подключения';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE onec_connection (
                id UUID PRIMARY KEY,
                owner_id UUID NOT NULL REFERENCES "user" (id) ON DELETE CASCADE,
                name VARCHAR(255) NOT NULL,
                token_hash VARCHAR(64) NOT NULL UNIQUE,
                base_id UUID,
                status VARCHAR(16) NOT NULL,
                versions JSONB NOT NULL,
                last_handshake_at TIMESTAMP(0) WITHOUT TIME ZONE,
                last_success_at TIMESTAMP(0) WITHOUT TIME ZONE,
                CONSTRAINT uniq_onec_owner_base UNIQUE (owner_id, base_id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE onec_organization (
                connection_id UUID NOT NULL REFERENCES onec_connection (id) ON DELETE CASCADE,
                guid UUID NOT NULL, name VARCHAR(255) NOT NULL, inn VARCHAR(10), kpp VARCHAR(9),
                company_id UUID REFERENCES companies (id) ON DELETE SET NULL,
                present BOOLEAN NOT NULL, enabled BOOLEAN NOT NULL,
                PRIMARY KEY (connection_id, guid)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_onec_organization_company ON onec_organization (company_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE onec_organization');
        $this->addSql('DROP TABLE onec_connection');
    }
}
