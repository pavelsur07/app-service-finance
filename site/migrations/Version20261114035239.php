<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceOzon (этап 4 marketplace-profit-pipeline): решения по кодам Ozon, итоги
 * переведённых дней, карантин. Только новые пустые таблицы — откат удаляет их без потери данных
 * (канон пересобирается из `sf_fact`).
 */
final class Version20261114035239 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceOzon: moz_source_type, moz_day, moz_quarantine';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE moz_day (company_id UUID NOT NULL, connection_ref VARCHAR(64) NOT NULL, operation_date DATE NOT NULL, facts_revision INT NOT NULL, rules_version VARCHAR(32) NOT NULL, fact_count INT NOT NULL, total_minor BIGINT NOT NULL, canon_minor BIGINT NOT NULL, quarantine_minor BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, unresolved_listing_count INT NOT NULL, translated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, connection_ref, operation_date))');
        $this->addSql('CREATE TABLE moz_quarantine (company_id UUID NOT NULL, connection_ref VARCHAR(64) NOT NULL, operation_date DATE NOT NULL, source_type VARCHAR(128) NOT NULL, amount_minor BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, row_count INT NOT NULL, source_refs JSONB NOT NULL, quarantined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, connection_ref, operation_date, source_type))');
        $this->addSql('CREATE TABLE moz_source_type (source_type VARCHAR(128) NOT NULL, name VARCHAR(255) NOT NULL, kind VARCHAR(64) DEFAULT NULL, status VARCHAR(16) NOT NULL, ai_draft JSONB DEFAULT NULL, first_seen_company_id UUID DEFAULT NULL, first_seen_date DATE DEFAULT NULL, first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, decided_by UUID DEFAULT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (source_type))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moz_day');
        $this->addSql('DROP TABLE moz_quarantine');
        $this->addSql('DROP TABLE moz_source_type');
    }
}
