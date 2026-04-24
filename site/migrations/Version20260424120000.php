<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceAds / Task-12-test: снятие UNIQUE (company_id, marketplace, report_date)
 * с `marketplace_ad_raw_documents`.
 *
 * В cron-driven pipeline (Task-11+) один `AdScheduledBatch` содержит до 10
 * CSV-файлов (по одному на кампанию) за один и тот же день. При ручной
 * распаковке (`ExtractBatchesToRawDocumentsAction`) на один отчётный день
 * создаётся N (≤ 10) `AdRawDocument` — по одному на CSV. Старый UNIQUE
 * `(company_id, marketplace, report_date)`, рассчитанный на «один день = один
 * документ» (Messenger-pipeline), блокирует это целиком.
 *
 * Идемпотентность теперь гарантируется меткой `batch_id=<uuid>\nfilename=<name>\n---\n`
 * в начале `raw_payload` (см. `ExtractBatchesToRawDocumentsAction::createOrFindRawDocument`).
 *
 * Старый Messenger-путь (`DownloadOzonAdReportHandler`) использует
 * `findByMarketplaceAndDate` (`findOneBy`) — без UNIQUE он продолжит
 * получать первый найденный документ; риск дубликатов отсутствует, так как
 * старый handler делает свой upsert внутри одного flush'а.
 */
final class Version20260424120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceAds / Task-12-test: drop UNIQUE (company_id, marketplace, report_date) on marketplace_ad_raw_documents';
    }

    public function up(Schema $schema): void
    {
        // UNIQUE был создан в Version20260412053206 как `CONSTRAINT ... UNIQUE (…)`
        // (table constraint), а не как отдельный `CREATE UNIQUE INDEX`. Postgres
        // хранит такой constraint вместе с backing-индексом: `DROP INDEX` не
        // удалит его, требуется `ALTER TABLE ... DROP CONSTRAINT`.
        $this->addSql('ALTER TABLE marketplace_ad_raw_documents DROP CONSTRAINT IF EXISTS uq_ad_raw_document_company_marketplace_date');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_ad_raw_documents
            ADD CONSTRAINT uq_ad_raw_document_company_marketplace_date
            UNIQUE (company_id, marketplace, report_date)
        SQL);
    }
}
