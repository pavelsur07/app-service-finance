<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contract-шаг налогового профиля (docs/tasks/vat-company-tax-settings): удаление companies.tax_system.
 * Значение перенесено в начальный профиль миграцией Version20261101120000, код колонку не маппит.
 * Применять только после деплоя кода без Company::$taxSystem — иначе старый код падает на SELECT.
 *
 * down() возвращает колонку и восстанавливает значение из начального профиля; сочетания, которых
 * в старом enum не было (ОСНО без НДС, АУСН, Патент, ЕСХН, УСН на общих ставках), становятся NULL.
 */
final class Version20261101180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Company: drop companies.tax_system (moved to company_tax_profiles)';
    }

    public function up(Schema $schema): void
    {
        // ALTER берёт ACCESS EXCLUSIVE на companies: не вставать в очередь за долгой транзакцией.
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        // Значение, не перенесённое в профиль, не удаляется молча.
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM companies c
                    LEFT JOIN company_tax_profiles p ON p.company_id = c.id AND p.valid_from IS NULL
                    WHERE c.tax_system IS NOT NULL AND p.id IS NULL
                ) THEN
                    RAISE EXCEPTION 'companies.tax_system has values without an initial company_tax_profiles row';
                END IF;
            END $$
            SQL);
        $this->addSql('ALTER TABLE companies DROP COLUMN tax_system');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD COLUMN tax_system VARCHAR(255) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE companies c
            SET tax_system = CASE
                WHEN p.tax_regime = 'USN' AND p.vat_mode = 'EXEMPT' THEN 'USN_NO_VAT'
                WHEN p.tax_regime = 'USN' AND p.vat_mode = 'USN_5' THEN 'USN_VAT_5'
                WHEN p.tax_regime = 'USN' AND p.vat_mode = 'USN_7' THEN 'USN_VAT_7'
                WHEN p.tax_regime = 'OSNO' AND p.vat_mode = 'GENERAL' THEN 'OSNO'
            END
            FROM company_tax_profiles p
            WHERE p.company_id = c.id AND p.valid_from IS NULL
            SQL);
    }
}
