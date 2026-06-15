<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create internal Ingestion tenant probe table for Doctrine company filter checks';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $this->abortIf(
            $platform !== 'postgresql',
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform),
        );

        $this->addSql('CREATE TABLE ingestion_tenant_probes (id UUID NOT NULL, company_id UUID NOT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_INGESTION_TENANT_PROBES_ID ON ingestion_tenant_probes (id)');
        $this->addSql('CREATE INDEX idx_ingestion_tenant_probes_company ON ingestion_tenant_probes (company_id)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $this->abortIf(
            $platform !== 'postgresql',
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform),
        );

        $this->addSql('DROP TABLE ingestion_tenant_probes');
    }
}
