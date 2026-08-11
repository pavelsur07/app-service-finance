<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Уникальность имени шаблона роли внутри компании.
 *
 * Индекс частичный: под `company_id IS NOT NULL` попадают только шаблоны компаний.
 * Системные шаблоны (`company_id IS NULL`) вставляет миграция Version20260811120000
 * с фиксированными UUID, их уникальность обеспечена seed'ом, а не индексом —
 * в Postgres NULL-ы в уникальном индексе считаются различными, поэтому обычный
 * unique по (company_id, name) системные строки всё равно не ограничил бы.
 */
final class Version20260811140000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return true;
    }

    public function getDescription(): string
    {
        return 'Add partial unique index on company_role (company_id, name) for company-owned templates';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL only.');

        $this->addSql(
            'CREATE UNIQUE INDEX uniq_company_role_company_name ON company_role (company_id, name) WHERE company_id IS NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL only.');

        $this->addSql('DROP INDEX IF EXISTS uniq_company_role_company_name');
    }
}
