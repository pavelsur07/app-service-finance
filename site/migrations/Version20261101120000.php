<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Ramsey\Uuid\Uuid;

/**
 * Налоговый профиль компании с историей (docs/tasks/vat-company-tax-settings/stages/stage-2.md).
 * Expand: новая таблица и перенос companies.tax_system в начальный профиль (valid_from IS NULL).
 * Колонка tax_system не удаляется — откат DROP TABLE возвращает исходное состояние без потери данных.
 */
final class Version20261101120000 extends AbstractMigration
{
    private const MAPPING = [
        'USN_NO_VAT' => ['USN', 'EXEMPT'],
        'USN_VAT_5' => ['USN', 'USN_5'],
        'USN_VAT_7' => ['USN', 'USN_7'],
        'OSNO' => ['OSNO', 'GENERAL'],
    ];

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

        $companies = $this->connection->fetchAllAssociative('SELECT id, tax_system FROM companies WHERE tax_system IS NOT NULL ORDER BY id');
        foreach ($companies as $company) {
            $mapped = self::MAPPING[(string) $company['tax_system']] ?? null;
            $this->abortIf(null === $mapped, sprintf('Unknown companies.tax_system "%s" for company %s', $company['tax_system'], $company['id']));
            \assert(null !== $mapped);

            $this->addSql(
                'INSERT INTO company_tax_profiles (id, company_id, tax_regime, vat_mode, valid_from, created_at, updated_at) VALUES (:id, :company_id, :tax_regime, :vat_mode, NULL, NOW(), NOW())',
                ['id' => Uuid::uuid7()->toString(), 'company_id' => $company['id'], 'tax_regime' => $mapped[0], 'vat_mode' => $mapped[1]],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE company_tax_profiles');
    }
}
