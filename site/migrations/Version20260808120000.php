<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Модульные роли доступа: таблица company_role (шаблоны прав по модулям),
 * колонка company_members.role_id и бэкфилл участников системными шаблонами.
 *
 * Недеструктивная (expand-only): старая строковая company_members.role сохраняется
 * и используется как fallback до полного перехода на шаблоны.
 *
 * Имена индексов/FK — по Doctrine-конвенции (см. doctrine:schema:create --dump-sql),
 * чтобы schema:validate не показывал расхождений.
 */
final class Version20260808120000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return true;
    }

    public function getDescription(): string
    {
        return 'Add company_role templates table, company_members.role_id FK, seed system roles and backfill members';
    }

    public function up(Schema $schema): void
    {
        $this->abortUnlessPostgreSql();

        $this->addSql('CREATE TABLE company_role (id UUID NOT NULL, company_id UUID DEFAULT NULL, name VARCHAR(128) NOT NULL, permissions JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_14049084979B1AD6 ON company_role (company_id)');
        $this->addSql('COMMENT ON COLUMN company_role.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE company_role ADD CONSTRAINT FK_14049084979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE company_members ADD role_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_65F2C828D60322AC ON company_members (role_id)');
        $this->addSql('ALTER TABLE company_members ADD CONSTRAINT FK_65F2C828D60322AC FOREIGN KEY (role_id) REFERENCES company_role (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Системные шаблоны. Источник значений — App\Company\Security\SystemCompanyRoles;
        // при изменении шаблонов держать оба места в синхроне. INSERT идемпотентен.
        $this->addSql(
            <<<'SQL'
                INSERT INTO company_role (id, company_id, name, permissions, created_at) VALUES
                ('00000000-0000-4000-8000-000000000001', NULL, 'Владелец', '{"finance": "write", "marketplace": "write", "deals": "write", "catalog": "write", "admin": "write"}', CURRENT_TIMESTAMP),
                ('00000000-0000-4000-8000-000000000002', NULL, 'Полный доступ', '{"finance": "write", "marketplace": "write", "deals": "write", "catalog": "write", "admin": "write"}', CURRENT_TIMESTAMP),
                ('00000000-0000-4000-8000-000000000003', NULL, 'Финансист', '{"finance": "write", "catalog": "read"}', CURRENT_TIMESTAMP),
                ('00000000-0000-4000-8000-000000000004', NULL, 'Менеджер маркетплейсов', '{"marketplace": "write", "catalog": "read"}', CURRENT_TIMESTAMP),
                ('00000000-0000-4000-8000-000000000005', NULL, 'Менеджер по продажам', '{"deals": "write", "catalog": "read", "marketplace": "read"}', CURRENT_TIMESTAMP)
                ON CONFLICT (id) DO NOTHING
                SQL,
        );

        // Бэкфилл по текущей строковой роли: поведение доступа не меняется.
        $this->addSql("UPDATE company_members SET role_id = '00000000-0000-4000-8000-000000000001' WHERE role = 'OWNER' AND role_id IS NULL");
        $this->addSql("UPDATE company_members SET role_id = '00000000-0000-4000-8000-000000000002' WHERE role = 'OPERATOR' AND role_id IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->abortUnlessPostgreSql();

        $this->addSql('ALTER TABLE company_members DROP CONSTRAINT FK_65F2C828D60322AC');
        $this->addSql('ALTER TABLE company_members DROP COLUMN role_id');
        $this->addSql('DROP TABLE company_role');
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
