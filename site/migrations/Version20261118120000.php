<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceFunnel (этап 5 marketplace-funnel): дневная таблица воронки и переведённые дни.
 * Только новые пустые таблицы — откат удаляет их без потери данных (пересобираются из `sf_fact`).
 */
final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceFunnel: mfn_day, mfn_row';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mfn_day (company_id UUID NOT NULL, connection_ref VARCHAR(64) NOT NULL, operation_date DATE NOT NULL, channel VARCHAR(16) NOT NULL, facts_revision INT NOT NULL, rules_version VARCHAR(32) NOT NULL, available BOOLEAN NOT NULL, row_count INT NOT NULL, control_sum BIGINT NOT NULL, translated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, channel, connection_ref, operation_date))');
        $this->addSql('CREATE TABLE mfn_row (company_id UUID NOT NULL, connection_ref VARCHAR(64) NOT NULL, operation_date DATE NOT NULL, marketplace_sku VARCHAR(64) NOT NULL, source VARCHAR(16) NOT NULL, channel VARCHAR(16) NOT NULL, impressions BIGINT DEFAULT NULL, impressions_search BIGINT DEFAULT NULL, card_views BIGINT DEFAULT NULL, cart_adds BIGINT DEFAULT NULL, orders BIGINT DEFAULT NULL, revenue_minor BIGINT DEFAULT NULL, buyouts BIGINT DEFAULT NULL, cancels BIGINT DEFAULT NULL, returns BIGINT DEFAULT NULL, PRIMARY KEY (company_id, channel, connection_ref, operation_date, marketplace_sku, source))');
        $this->addSql('CREATE INDEX idx_mfn_row_company_date ON mfn_row (company_id, operation_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mfn_row');
        $this->addSql('DROP TABLE mfn_day');
    }
}
