<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace tenant ingestion counterparties with global system counterparties';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('CREATE TABLE system_counterparties (id UUID NOT NULL, source VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, inn VARCHAR(32) DEFAULT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_system_counterparties_source ON system_counterparties (source)');
        $this->addSql("INSERT INTO system_counterparties (id, source, name, inn, created_at) VALUES ('1cbbfc7c-72ad-5505-8743-be71bdde6dc1', 'ozon', 'Ozon', NULL, NOW())");
        $this->addSql("INSERT INTO system_counterparties (id, source, name, inn, created_at) VALUES ('95d09265-b44f-5b95-a12c-f1e3332c657d', 'wildberries', 'Wildberries', NULL, NOW())");

        $counterpartyCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ingest_counterparties');
        $this->abortIf(
            $counterpartyCount > 0,
            'ingest_counterparties must be empty before dropping it. Review and migrate existing rows first.',
        );

        $this->addSql('DROP TABLE ingest_counterparties');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('CREATE TABLE ingest_counterparties (id UUID NOT NULL, company_id UUID NOT NULL, source VARCHAR(64) NOT NULL, external_key VARCHAR(255) NOT NULL, name VARCHAR(500) NOT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_counterparty_natural ON ingest_counterparties (company_id, source, external_key)');
        $this->addSql('DROP TABLE system_counterparties');
    }
}
