<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260626110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add semantic external category identity fields';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('ALTER TABLE ingest_external_categories ADD external_code VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ingest_external_categories ADD provider_label VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ingest_external_categories ADD display_label VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_ingest_ext_category_external_code ON ingest_external_categories (source, resource_type, external_code)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('DROP INDEX idx_ingest_ext_category_external_code');
        $this->addSql('ALTER TABLE ingest_external_categories DROP external_code');
        $this->addSql('ALTER TABLE ingest_external_categories DROP provider_label');
        $this->addSql('ALTER TABLE ingest_external_categories DROP display_label');
    }
}
