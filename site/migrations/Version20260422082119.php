<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceAds: step 2/5 async-poll redesign.
 *
 * Добавляет колонку next_poll_at в marketplace_ad_pending_reports — плановое
 * время следующего опроса для будущей cron-задачи (step 3), реализующей
 * экспоненциальный backoff вместо фиксированного polling раз в 2 минуты.
 *
 * Partial-индекс idx_ad_pending_report_next_poll покрывает только in-flight
 * записи (finalized_at IS NULL) и заточен под планируемый паттерн запроса
 * cron-а: «WHERE finalized_at IS NULL AND next_poll_at <= NOW()».
 *
 * В этой миграции только схема. Колонку никто не читает/не пишет — это
 * сделает step 3. Существующие строки получают next_poll_at = NULL; для
 * cron-а это будет означать «опросить немедленно».
 */
final class Version20260422082119 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add next_poll_at to marketplace_ad_pending_reports for async-poll backoff';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_ad_pending_reports
            ADD COLUMN next_poll_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
        SQL);

        // Partial index: only rows that are still in-flight AND have a scheduled
        // next poll. The poll cron queries "WHERE finalized_at IS NULL AND
        // next_poll_at <= NOW()" — this index is tailored to that access pattern.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_ad_pending_report_next_poll
            ON marketplace_ad_pending_reports (next_poll_at)
            WHERE finalized_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN marketplace_ad_pending_reports.next_poll_at
            IS '(DC2Type:datetime_immutable)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_ad_pending_report_next_poll');
        $this->addSql('ALTER TABLE marketplace_ad_pending_reports DROP COLUMN IF EXISTS next_poll_at');
    }
}
