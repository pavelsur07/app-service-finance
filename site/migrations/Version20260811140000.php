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
 *
 * Индекс функциональный, по `LOWER(name)`: приложение сравнивает имена регистронезависимо
 * (CompanyRoleRepository::findOneByCompanyAndName), и правило должно совпадать с БД —
 * иначе гонка двух запросов с «Финансист»/«финансист» прошла бы индекс, а точный индекс
 * при одинаковых именах давал бы необработанный UniqueConstraintViolationException.
 *
 * ВНИМАНИЕ при генерации будущих миграций: частичный функциональный индекс не выражается
 * атрибутами ORM, поэтому `doctrine:schema:update --dump-sql` и `migrations:diff` предлагают
 * `DROP INDEX uniq_company_role_company_name`. Это ложное срабатывание — такую строку из
 * сгенерированной миграции нужно удалять. Деплой использует migrations:migrate, не schema:update.
 */
final class Version20260811140000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return true;
    }

    public function getDescription(): string
    {
        return 'Add partial case-insensitive unique index on company_role (company_id, LOWER(name)) for company-owned templates';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL only.');

        $this->addSql(
            'CREATE UNIQUE INDEX uniq_company_role_company_name ON company_role (company_id, LOWER(name)) WHERE company_id IS NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL only.');

        $this->addSql('DROP INDEX IF EXISTS uniq_company_role_company_name');
    }
}
