<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceAds: создание таблицы `marketplace_ad_load_jobs` для трекинга
 * прогресса пакетной загрузки рекламных отчётов за период.
 *
 * Счётчики (loaded_days/processed_days/failed_days) инкрементируются
 * атомарным `UPDATE ... SET x = x + :delta` из параллельных воркеров.
 */
final class Version20260418000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceAds: create marketplace_ad_load_jobs table for batch load progress tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_ad_load_jobs (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                marketplace VARCHAR(50) NOT NULL,
                date_from DATE NOT NULL,
                date_to DATE NOT NULL,
                total_days INT NOT NULL,
                loaded_days INT NOT NULL DEFAULT 0,
                processed_days INT NOT NULL DEFAULT 0,
                failed_days INT NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                failure_reason TEXT DEFAULT NULL,
                started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_ad_load_job_company ON marketplace_ad_load_jobs (company_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_ad_load_job_company_marketplace_status
            ON marketplace_ad_load_jobs (company_id, marketplace, status)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_ad_load_job_company_marketplace_range
            ON marketplace_ad_load_jobs (company_id, marketplace, date_from, date_to)
        SQL);
        $this->addSql("COMMENT ON COLUMN marketplace_ad_load_jobs.date_from IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_load_jobs.date_to IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_load_jobs.started_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_load_jobs.finished_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_load_jobs.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_load_jobs.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_ad_load_jobs');
    }
}
