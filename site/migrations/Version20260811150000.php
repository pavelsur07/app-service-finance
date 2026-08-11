<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ссылки на шаблон роли переводятся с ON DELETE SET NULL на RESTRICT.
 *
 * Приложение уже запрещает удалять назначенный шаблон, но проверка и удаление шли
 * двумя запросами без блокировки: при конкурентном назначении между ними role_id
 * обнулялся. Инвариант «назначенный шаблон не удаляется» должна держать БД.
 *
 * Каскад по companies → company_role сохраняется: удаление компании без участников
 * по-прежнему сносит её шаблоны (тест testCompanyDeletionStillCascadesUnassignedRoles).
 * Компанию с участниками удалить нельзя и до этой миграции — `fk_company_members_company`
 * объявлен NO ACTION, а ORM участников не каскадит. Это отдельный pre-existing дефект,
 * вынесен в follow-up и данной миграцией не затрагивается.
 */
final class Version20260811150000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return true;
    }

    public function getDescription(): string
    {
        return 'Switch company_members.role_id and company_invites.role_id FKs to ON DELETE RESTRICT';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL only.');

        $this->addSql('ALTER TABLE company_members DROP CONSTRAINT FK_65F2C828D60322AC');
        $this->addSql('ALTER TABLE company_members ADD CONSTRAINT FK_65F2C828D60322AC FOREIGN KEY (role_id) REFERENCES company_role (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE company_invites DROP CONSTRAINT FK_232C70BBD60322AC');
        $this->addSql('ALTER TABLE company_invites ADD CONSTRAINT FK_232C70BBD60322AC FOREIGN KEY (role_id) REFERENCES company_role (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL only.');

        $this->addSql('ALTER TABLE company_invites DROP CONSTRAINT FK_232C70BBD60322AC');
        $this->addSql('ALTER TABLE company_invites ADD CONSTRAINT FK_232C70BBD60322AC FOREIGN KEY (role_id) REFERENCES company_role (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE company_members DROP CONSTRAINT FK_65F2C828D60322AC');
        $this->addSql('ALTER TABLE company_members ADD CONSTRAINT FK_65F2C828D60322AC FOREIGN KEY (role_id) REFERENCES company_role (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
