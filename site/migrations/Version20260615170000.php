<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure Ingestion raw record dedup unique index includes resource type';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('DROP INDEX IF EXISTS uniq_ingest_raw_company_source_external_hash');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_ingest_raw_company_source_resource_external_hash ON ingest_raw_records (company_id, source, resource_type, external_id, hash)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('DROP INDEX IF EXISTS uniq_ingest_raw_company_source_resource_external_hash');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_ingest_raw_company_source_external_hash ON ingest_raw_records (company_id, source, external_id, hash)');
    }
}
