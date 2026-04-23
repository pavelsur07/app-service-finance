<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceAds / Task-11.1: cron-driven pipeline.
 *
 * Создаёт таблицу `marketplace_ad_scheduled_batches` — план последовательной
 * обработки батчей Ozon Performance (1 активный отчёт на аккаунт, батчи по
 * 10 кампаний, ≤ 62 дня). Cron-команды (POST → poll → download) берут по
 * одному батчу за тик, отсюда state machine `PLANNED → IN_FLIGHT → OK |
 * FAILED | ABANDONED`.
 *
 * Индексы рассчитаны на hot-path'ы cron-ов:
 *  - idx_asb_scheduler — planner/poster: выбрать готовый PLANNED;
 *  - idx_asb_poller    — poller: выбрать IN_FLIGHT батчи;
 *  - idx_asb_job       — finalizer: агрегировать статусы по job'у;
 *  - idx_asb_job_batch — идемпотентность планирования (UNIQUE).
 *
 * Эта миграция — только схема. Код (Entity, Repository, команды) появится
 * в последующих шагах 11.2+.
 */
final class Version20260423155717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceAds: create marketplace_ad_scheduled_batches for cron-driven Ozon ad pipeline';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_ad_scheduled_batches (
                id UUID NOT NULL,
                job_id UUID NOT NULL,
                company_id UUID NOT NULL,
                marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',

                campaign_ids JSONB NOT NULL,
                date_from DATE NOT NULL,
                date_to DATE NOT NULL,
                batch_index INT NOT NULL,

                state VARCHAR(32) NOT NULL DEFAULT 'PLANNED',

                scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                started_at   TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                finished_at  TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,

                ozon_uuid VARCHAR(64),

                storage_path VARCHAR(512),
                file_hash    VARCHAR(64),
                file_size    INT,

                retry_count INT NOT NULL DEFAULT 0,
                last_error  TEXT,

                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),

                PRIMARY KEY(id),
                CONSTRAINT fk_asb_job FOREIGN KEY (job_id)
                    REFERENCES marketplace_ad_load_jobs(id)
            )
        SQL);

        // Hot path: scheduler берёт PLANNED готовые к обработке.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_asb_scheduler
            ON marketplace_ad_scheduled_batches (scheduled_at)
            WHERE state = 'PLANNED'
        SQL);

        // Hot path: poller берёт IN_FLIGHT.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_asb_poller
            ON marketplace_ad_scheduled_batches (id)
            WHERE state = 'IN_FLIGHT'
        SQL);

        // Finalizer: все batch'и конкретного job'а.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_asb_job
            ON marketplace_ad_scheduled_batches (job_id, state)
        SQL);

        // Idempotency: не создавать дубли batch'ей в одном job'е.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX idx_asb_job_batch
            ON marketplace_ad_scheduled_batches (job_id, batch_index)
        SQL);

        // Doctrine type hints — Entity в Task-11.2 будет использовать
        // стандартный `datetime_immutable` mapping без кастомизации.
        $this->addSql("COMMENT ON COLUMN marketplace_ad_scheduled_batches.scheduled_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_scheduled_batches.started_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_scheduled_batches.finished_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_scheduled_batches.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_scheduled_batches.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS marketplace_ad_scheduled_batches');
    }
}
