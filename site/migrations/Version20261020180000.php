<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Профиль бизнеса компании — по нему онбординг выбирает стартовые шаблоны. Существующие
 * компании получают MARKETPLACE: это профиль шаблонов, которые у них уже есть. Константный
 * DEFAULT в PostgreSQL 11+ не перезаписывает таблицу.
 */
final class Version20261020180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add companies.business_profile (MARKETPLACE for existing companies)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $this->addSql("ALTER TABLE companies ADD business_profile VARCHAR(32) DEFAULT 'MARKETPLACE' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $this->addSql('ALTER TABLE companies DROP business_profile');
    }

    private function abortIfNotPostgres(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );
    }
}
