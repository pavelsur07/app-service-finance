<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceAds: вводит ledger-таблицу `marketplace_ad_chunk_progress` для
 * идемпотентного учёта выгруженных чанков рекламной статистики.
 *
 * Мотивация (PR #1572, gemini/codex review):
 *   Старая эвристика детекции retry оркестратора в FetchOzonAdStatisticsHandler
 *   (`created === 0 && updated > 0`) ломалась в двух сценариях:
 *     1) перезапуск загрузки того же периода с новым jobId — все документы
 *        existing → chunks_completed никогда не инкрементился → новый job
 *        застревал в RUNNING навсегда;
 *     2) легитимный первый fetch чанка, где все дни уже были предзагружены
 *        (CLI/manual pre-seed) — та же картина.
 *
 *   Ledger с UNIQUE (job_id, date_from, date_to) решает обе проблемы:
 *   INSERT ... ON CONFLICT DO NOTHING атомарно отвечает «новый чанк» vs
 *   «уже учтён», независимо от состояния AdRawDocument.
 */
final class Version20260418000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceAds: add marketplace_ad_chunk_progress ledger (idempotent chunks_completed gating)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_ad_chunk_progress (
                id UUID NOT NULL,
                job_id UUID NOT NULL,
                company_id UUID NOT NULL,
                date_from DATE NOT NULL,
                date_to DATE NOT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_ad_chunk_progress_job_range
                ON marketplace_ad_chunk_progress (job_id, date_from, date_to)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_ad_chunk_progress_company
                ON marketplace_ad_chunk_progress (company_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_ad_chunk_progress');
    }
}
