<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803200103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expand marketplace_connections: encrypted api_key storage (ciphertext + key version), nullable, non-destructive';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        // Expand: новые nullable-колонки, plaintext api_key не трогаем —
        // старый код продолжает работать, backfill выполняется отдельной командой.
        $this->addSql('ALTER TABLE marketplace_connections ADD api_key_encrypted TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_connections ADD api_key_key_version VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('ALTER TABLE marketplace_connections DROP api_key_encrypted');
        $this->addSql('ALTER TABLE marketplace_connections DROP api_key_key_version');
    }
}
