<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Снимки сверки с Ozon: одна запись на компанию и период + строки по блокам и категориям.
 * Аддитивная миграция: существующие таблицы и данные не затрагиваются.
 * Отдельного индекса (company_id, period_from) нет: его покрывает левый префикс уникального индекса.
 */
final class Version20261001100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create marketplace_ozon_reconciliation_runs and marketplace_ozon_reconciliation_lines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_ozon_reconciliation_runs (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                period_from DATE NOT NULL,
                period_to DATE NOT NULL,
                currency VARCHAR(3) NOT NULL,
                raw_days_expected INT NOT NULL,
                raw_days_present INT NOT NULL,
                realization_present BOOLEAN NOT NULL,
                outside_raw_costs_minor BIGINT NOT NULL,
                overall_status VARCHAR(32) NOT NULL,
                mismatch_count INT NOT NULL,
                checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_mozrr_company_period ON marketplace_ozon_reconciliation_runs (company_id, period_from, period_to)');
        $this->addSql("COMMENT ON COLUMN marketplace_ozon_reconciliation_runs.period_from IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ozon_reconciliation_runs.period_to IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ozon_reconciliation_runs.checked_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ozon_reconciliation_runs.created_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_ozon_reconciliation_lines (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                run_id UUID NOT NULL,
                check_type VARCHAR(32) NOT NULL,
                block VARCHAR(32) NOT NULL,
                category_code VARCHAR(64) DEFAULT '' NOT NULL,
                source_minor BIGINT DEFAULT NULL,
                target_minor BIGINT DEFAULT NULL,
                delta_minor BIGINT DEFAULT NULL,
                source_count INT DEFAULT NULL,
                target_count INT DEFAULT NULL,
                status VARCHAR(32) NOT NULL,
                note VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_mozrl_run_check_block_category ON marketplace_ozon_reconciliation_lines (run_id, check_type, block, category_code)');
        $this->addSql('CREATE INDEX idx_mozrl_company_run ON marketplace_ozon_reconciliation_lines (company_id, run_id)');
    }

    /**
     * Снимки — производные данные, их можно пересчитать командой сверки; потеря при откате допустима.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_ozon_reconciliation_lines');
        $this->addSql('DROP TABLE marketplace_ozon_reconciliation_runs');
    }
}
