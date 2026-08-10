<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Модульные роли доступа: приглашения получают role_id (шаблон, который будет
 * назначен принявшему пользователю). Недеструктивная (expand-only).
 *
 * Имена индекса/FK — по Doctrine-конвенции (см. doctrine:schema:create --dump-sql).
 */
final class Version20260808130000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return true;
    }

    public function getDescription(): string
    {
        return 'Add company_invites.role_id FK to company_role';
    }

    public function up(Schema $schema): void
    {
        $this->abortUnlessPostgreSql();

        $this->addSql('ALTER TABLE company_invites ADD COLUMN IF NOT EXISTS role_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_232C70BBD60322AC ON company_invites (role_id)');
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'fk_232c70bbd60322ac'
                ) THEN
                    ALTER TABLE company_invites ADD CONSTRAINT FK_232C70BBD60322AC FOREIGN KEY (role_id) REFERENCES company_role (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
                END IF;
            END $$;
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortUnlessPostgreSql();

        $this->addSql('ALTER TABLE company_invites DROP CONSTRAINT IF EXISTS FK_232C70BBD60322AC');
        $this->addSql('DROP INDEX IF EXISTS IDX_232C70BBD60322AC');
        $this->addSql('ALTER TABLE company_invites DROP COLUMN IF EXISTS role_id');
    }

    private function abortUnlessPostgreSql(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );
    }
}
