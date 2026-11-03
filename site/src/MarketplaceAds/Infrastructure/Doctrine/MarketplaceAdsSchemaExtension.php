<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension;
use Doctrine\DBAL\Schema\Schema;

/**
 * Реклама маркетплейсов: ключи на задание загрузки, частичные индексы очередей планировщика и опроса.
 *
 * Объекты существуют в БД (миграции), но не выводятся из атрибутов сущностей; без этого описания
 * diff схемы предлагает их удалить. Устройство — {@see SchemaExtension}.
 */
final readonly class MarketplaceAdsSchemaExtension implements SchemaExtension
{
    public function extend(Schema $schema): void
    {
        $schema->getTable('marketplace_ad_scheduled_batches')->addForeignKeyConstraint('marketplace_ad_load_jobs', ['job_id'], ['id'], ['onDelete' => null], 'fk_asb_job');
        $schema->getTable('marketplace_ad_chunk_progress')->addForeignKeyConstraint('marketplace_ad_load_jobs', ['job_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_ad_chunk_progress_job');
        $schema->getTable('marketplace_ad_raw_documents')->addIndex(['file_hash'], 'idx_ad_raw_docs_file_hash', [], ['where' => '(file_hash IS NOT NULL)']);
        $schema->getTable('marketplace_ad_scheduled_batches')->addIndex(['id'], 'idx_asb_poller', [], ['where' => '((state)::text = \'IN_FLIGHT\'::text)']);
        $schema->getTable('marketplace_ad_scheduled_batches')->addIndex(['scheduled_at'], 'idx_asb_scheduler', [], ['where' => '((state)::text = \'PLANNED\'::text)']);
        $schema->getTable('marketplace_ad_pending_reports')->addIndex(['next_poll_at'], 'idx_ad_pending_report_next_poll', [], ['where' => '(finalized_at IS NULL)']);
    }
}
