<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Подключение 1С принадлежит компании. Компания берётся из записи CREATE в
 * audit_log (её писал CreateConnectionAction с активной компанией владельца);
 * подключения без такой записи или с несколькими компаниями миграция
 * перечисляет и останавливается — не угадывает.
 */
final class Version20261127090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'onec_connection.company_id из audit_log, уникальность (company_id, base_id), owner_id → created_by_user_id; onec_organization без company_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE orphans text;
            BEGIN
                SELECT string_agg(c.id::text, ', ' ORDER BY c.id) INTO orphans FROM onec_connection c
                WHERE (SELECT COUNT(DISTINCT a.company_id) FROM audit_log a
                       WHERE a.entity_class = 'App\OneC\Entity\OneCConnection' AND a.entity_id = c.id::text AND a.action = 'CREATE') <> 1;
                IF orphans IS NOT NULL THEN
                    RAISE EXCEPTION 'onec_connection без однозначной компании в audit_log: %', orphans;
                END IF;
            END $$
            SQL);
        $this->addSql('ALTER TABLE onec_connection ADD company_id UUID DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE onec_connection c SET company_id = a.company_id
            FROM audit_log a
            WHERE a.entity_class = 'App\OneC\Entity\OneCConnection' AND a.entity_id = c.id::text AND a.action = 'CREATE'
            SQL);
        $this->addSql('ALTER TABLE onec_connection ALTER company_id SET NOT NULL');
        $this->addSql('ALTER TABLE onec_connection ADD CONSTRAINT onec_connection_company_id_fkey FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE onec_connection DROP CONSTRAINT uniq_onec_owner_base');
        $this->addSql('ALTER TABLE onec_connection ADD CONSTRAINT uniq_onec_company_base UNIQUE (company_id, base_id)');
        // Автор только для аудита: удаление пользователя не удаляет подключение компании.
        $this->addSql('ALTER TABLE onec_connection DROP CONSTRAINT onec_connection_owner_id_fkey');
        $this->addSql('ALTER TABLE onec_connection RENAME COLUMN owner_id TO created_by_user_id');
        $this->addSql('ALTER TABLE onec_connection ALTER created_by_user_id DROP NOT NULL');
        $this->addSql('ALTER TABLE onec_connection ADD CONSTRAINT onec_connection_created_by_user_id_fkey FOREIGN KEY (created_by_user_id) REFERENCES "user" (id) ON DELETE SET NULL');
        // Организация относится к компании своего подключения; привязка — флаг enabled.
        $this->addSql('ALTER TABLE onec_organization DROP COLUMN company_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE onec_organization ADD company_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE onec_organization ADD CONSTRAINT onec_organization_company_id_fkey FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_onec_organization_company ON onec_organization (company_id)');
        $this->addSql('ALTER TABLE onec_connection DROP CONSTRAINT onec_connection_created_by_user_id_fkey');
        $this->addSql('ALTER TABLE onec_connection RENAME COLUMN created_by_user_id TO owner_id');
        $this->addSql('ALTER TABLE onec_connection ALTER owner_id SET NOT NULL');
        $this->addSql('ALTER TABLE onec_connection ADD CONSTRAINT onec_connection_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE onec_connection DROP CONSTRAINT uniq_onec_company_base');
        $this->addSql('ALTER TABLE onec_connection ADD CONSTRAINT uniq_onec_owner_base UNIQUE (owner_id, base_id)');
        $this->addSql('ALTER TABLE onec_connection DROP COLUMN company_id');
    }
}
