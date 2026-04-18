<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceAds: создание таблицы `marketplace_ad_chunk_progress` —
 * фактов завершения чанков загрузки рекламной статистики.
 *
 * Уникальный индекс `(job_id, date_from, date_to)` гарантирует идемпотентность
 * на уровне БД: повторная обработка того же чанка (Messenger retry) не
 * породит дубликата и не инкрементит `chunks_completed` дважды.
 *
 * FK на `marketplace_ad_load_jobs(id)` с `ON DELETE CASCADE` — при удалении
 * job'а прогресс чанков уходит вместе с ним (сирот не остаётся).
 */
final class Version20260418000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceAds: create marketplace_ad_chunk_progress table for per-chunk idempotency';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_ad_chunk_progress (
                id UUID NOT NULL,
                job_id UUID NOT NULL,
                date_from DATE NOT NULL,
                date_to DATE NOT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT uq_ad_chunk_progress_job_dates UNIQUE (job_id, date_from, date_to),
                CONSTRAINT fk_ad_chunk_progress_job
                    FOREIGN KEY (job_id)
                    REFERENCES marketplace_ad_load_jobs (id)
                    ON DELETE CASCADE
                    NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_ad_chunk_progress_job ON marketplace_ad_chunk_progress (job_id)');
        $this->addSql("COMMENT ON COLUMN marketplace_ad_chunk_progress.date_from IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_chunk_progress.date_to IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_chunk_progress.completed_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_ad_chunk_progress');
    }
}
