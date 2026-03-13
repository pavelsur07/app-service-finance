<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323093008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MoySklad: add moysklad_connections table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE moysklad_connections (
            id UUID NOT NULL,
            company_id UUID NOT NULL,
            name VARCHAR(255) NOT NULL,
            base_url VARCHAR(255) NOT NULL,
            login VARCHAR(255) DEFAULT NULL,
            access_token TEXT DEFAULT NULL,
            refresh_token TEXT DEFAULT NULL,
            token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            is_active BOOLEAN NOT NULL,
            last_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_moysklad_connections_company_active ON moysklad_connections (company_id, is_active)');
        $this->addSql('CREATE INDEX idx_moysklad_connections_company_name ON moysklad_connections (company_id, name)');
        $this->addSql('CREATE UNIQUE INDEX uniq_moysklad_connections_company_name ON moysklad_connections (company_id, name)');
        $this->addSql("COMMENT ON COLUMN moysklad_connections.token_expires_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN moysklad_connections.last_sync_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN moysklad_connections.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN moysklad_connections.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moysklad_connections');
    }
}
