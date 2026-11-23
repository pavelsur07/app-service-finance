<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceEconomics (этап 5 marketplace-profit-pipeline): витрина экономики SKU по дням.
 * Только новые пустые таблицы — откат удаляет их без потери данных (витрина пересобирается из канона).
 */
final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceEconomics: me_day_line, me_day';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE me_day_line (id UUID NOT NULL, company_id UUID NOT NULL, channel VARCHAR(32) NOT NULL, connection_ref VARCHAR(64) NOT NULL, operation_date DATE NOT NULL, listing_id UUID DEFAULT NULL, measure VARCHAR(64) NOT NULL, level VARCHAR(16) NOT NULL, amount_minor BIGINT NOT NULL, quantity INT DEFAULT NULL, allocation_key VARCHAR(32) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_me_day_line_company_date ON me_day_line (company_id, operation_date)');
        $this->addSql('CREATE TABLE me_day (company_id UUID NOT NULL, channel VARCHAR(32) NOT NULL, connection_ref VARCHAR(64) NOT NULL, operation_date DATE NOT NULL, rules_version VARCHAR(32) NOT NULL, canon_minor BIGINT NOT NULL, unallocated_minor BIGINT NOT NULL, cost_price_minor BIGINT NOT NULL, without_cost_price INT NOT NULL, line_count INT NOT NULL, currency VARCHAR(3) NOT NULL, refreshed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (company_id, channel, connection_ref, operation_date))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE me_day_line');
        $this->addSql('DROP TABLE me_day');
    }
}
