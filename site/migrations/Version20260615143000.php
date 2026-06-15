<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Ingestion credentials storage with SecretCodec-backed payload column';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('CREATE TABLE ingestion_credentials (id UUID NOT NULL, company_id UUID NOT NULL, connection_ref VARCHAR(255) NOT NULL, type VARCHAR(100) NOT NULL, payload TEXT NOT NULL, key_version INT DEFAULT 0 NOT NULL, expires_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_ingestion_credentials_company ON ingestion_credentials (company_id)');
        $this->addSql('CREATE INDEX idx_ingestion_credentials_connection_ref ON ingestion_credentials (connection_ref)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingestion_credentials_company_ref_type ON ingestion_credentials (company_id, connection_ref, type)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('DROP TABLE ingestion_credentials');
    }
}
