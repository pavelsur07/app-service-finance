<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Налоговый профиль компании с историей (docs/tasks/vat-company-tax-settings/stages/stage-2.md).
 * Expand: новая таблица и перенос companies.tax_system в начальный профиль (valid_from IS NULL).
 * Колонка tax_system не удаляется. Откат DROP TABLE возвращает состояние до миграции, но теряет периоды,
 * сохранённые после деплоя, а tax_system к тому времени устаревает (новый код его не обновляет).
 */
final class Version20261101120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Company: company_tax_profiles (tax regime + VAT mode by month), backfill from companies.tax_system';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE company_tax_profiles (id UUID NOT NULL, company_id UUID NOT NULL, tax_regime VARCHAR(16) NOT NULL, vat_mode VARCHAR(16) NOT NULL, valid_from DATE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE company_tax_profiles ADD CONSTRAINT fk_company_tax_profiles_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
        // Один начальный (valid_from IS NULL) и один период на месяц; индекс же обслуживает поиск по компании.
        $this->addSql('CREATE UNIQUE INDEX uniq_company_tax_profiles_company_valid_from ON company_tax_profiles (company_id, valid_from) NULLS NOT DISTINCT');
        $this->addSql('ALTER TABLE company_tax_profiles ADD CONSTRAINT chk_company_tax_profiles_first_day CHECK (valid_from IS NULL OR EXTRACT(DAY FROM valid_from) = 1)');

        // Backfill одним INSERT … SELECT: неизвестное значение tax_system останавливает миграцию,
        // а не теряется молча. ID — gen_random_uuid() (v4): uuidv7() в PostgreSQL 15 нет.
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM companies WHERE tax_system IS NOT NULL AND tax_system NOT IN ('USN_NO_VAT', 'USN_VAT_5', 'USN_VAT_7', 'OSNO')) THEN
                    RAISE EXCEPTION 'Unknown companies.tax_system value';
                END IF;
            END $$
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO company_tax_profiles (id, company_id, tax_regime, vat_mode, valid_from, created_at, updated_at)
            SELECT gen_random_uuid(),
                   id,
                   CASE tax_system WHEN 'OSNO' THEN 'OSNO' ELSE 'USN' END,
                   CASE tax_system WHEN 'USN_NO_VAT' THEN 'EXEMPT' WHEN 'USN_VAT_5' THEN 'USN_5' WHEN 'USN_VAT_7' THEN 'USN_7' WHEN 'OSNO' THEN 'GENERAL' END,
                   NULL,
                   NOW(),
                   NOW()
            FROM companies
            WHERE tax_system IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE company_tax_profiles');
    }
}
