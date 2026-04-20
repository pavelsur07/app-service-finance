<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceAds: persistence для запрошенных отчётов Ozon Performance.
 *
 * Создаёт marketplace_ad_pending_reports — хранит UUID отчёта, запрошенного
 * у Ozon Performance POST /api/client/statistics, вместе с контекстом запроса
 * (company_id, date range, campaign_ids, привязанный AdLoadJob) и живым
 * состоянием polling'а (state, poll_attempts, first_non_pending_at,
 * last_checked_at, finalized_at, error_message).
 *
 * Цель: UUID не теряется при exception mid-polling, а каждая итерация
 * pollReport фиксирует state/attempt в БД (было — только в локальной
 * переменной процесса).
 *
 * Индексы:
 *  - idx_ad_pending_report_company — отчёты конкретной компании;
 *  - idx_ad_pending_report_job     — связь с AdLoadJob (resume on retry);
 *  - idx_ad_pending_report_state   — поиск in-flight / abandoned записей;
 *  - uq_ad_pending_report_ozon_uuid — UNIQUE на ozon_uuid (один UUID = одна запись).
 */
final class Version20260420120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceAds: create marketplace_ad_pending_reports for Ozon report UUID persistence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_ad_pending_reports (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                ozon_uuid VARCHAR(64) NOT NULL,
                date_from DATE NOT NULL,
                date_to DATE NOT NULL,
                campaign_ids JSON NOT NULL,
                state VARCHAR(32) NOT NULL,
                job_id UUID DEFAULT NULL,
                requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                first_non_pending_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                poll_attempts INT NOT NULL DEFAULT 0,
                finalized_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_ad_pending_report_ozon_uuid ON marketplace_ad_pending_reports (ozon_uuid)');
        $this->addSql('CREATE INDEX idx_ad_pending_report_company ON marketplace_ad_pending_reports (company_id)');
        $this->addSql('CREATE INDEX idx_ad_pending_report_job ON marketplace_ad_pending_reports (job_id)');
        $this->addSql('CREATE INDEX idx_ad_pending_report_state ON marketplace_ad_pending_reports (state)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_ad_pending_reports');
    }
}
