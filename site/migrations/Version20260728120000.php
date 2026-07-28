<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Слияние дублей WB-листингов «без размера».
 *
 * Причина дублей:
 *   WB отдаёт безразмерный товар как ts_name="0" в отчётах продаж/затрат
 *   и как "" в карточках каталога. normalizeWbSize() приводил к 'UNKNOWN'
 *   только пустую строку, поэтому на один nm_id заводилось два листинга:
 *   size='0' (с продажами и затратами) и size='UNKNOWN' (с chrtId).
 *   Дедуп-миграция Version20260331120000 их не схлопывает — она
 *   партиционирует по size, для неё это разные группы.
 *
 * Что делает миграция:
 *   1. Для каждого (company_id, nm_id) с обеими половинами определяет
 *      канонический листинг — самый старый по created_at.
 *   2. Переключает на него все таблицы с listing_id, разрешая конфликты
 *      уникальных ключей. Строки рекламных документов суммируются, а не
 *      удаляются — иначе теряется рекламный расход.
 *   3. Переносит на канонический листинг идентичность дубля (chrtId,
 *      product_id, supplier_sku, name), если своей нет.
 *   4. Удаляет дубли и приводит size='0' → 'UNKNOWN' для Wildberries.
 *
 * Листинги с настоящими размерами (XS/S/M/L, 42 и т.п.) и другие
 * маркетплейсы не затрагиваются.
 */
final class Version20260728120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Merge duplicate Wildberries listings (size 0 vs UNKNOWN for the same nm_id) and normalize size to UNKNOWN';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        if (!$schema->hasTable('marketplace_listings')) {
            return;
        }

        // ── 1. Карта дубль → канонический листинг со снимком идентичности дубля ──
        //    Идентичность переносится позже: частичные уникальные индексы на
        //    marketplace_variant_id и product_id не дадут скопировать её,
        //    пока дубль ещё существует.
        $this->addSql(<<<'SQL'
            CREATE TABLE _wb_size_merge_map AS
            SELECT
                l.id                     AS dup_id,
                r.keep_id                AS keep_id,
                l.marketplace_variant_id AS dup_variant_id,
                l.product_id             AS dup_product_id,
                l.supplier_sku           AS dup_supplier_sku,
                l.name                   AS dup_name
            FROM marketplace_listings l
            JOIN (
                SELECT
                    id,
                    FIRST_VALUE(id) OVER (
                        PARTITION BY company_id, marketplace_sku
                        ORDER BY created_at ASC, id ASC
                    ) AS keep_id
                FROM marketplace_listings
                WHERE marketplace = 'wildberries'
                  AND size IN ('0', 'UNKNOWN')
            ) r ON r.id = l.id
            WHERE l.id <> r.keep_id
        SQL);

        // ── 2. Конфликты уникальных ключей, где listing_id входит в ключ ──
        //    Побеждает строка канонического листинга.
        $this->addSql(<<<'SQL'
            DELETE FROM marketplace_inventory_cost_prices dup
            USING _wb_size_merge_map m
            WHERE dup.listing_id = m.dup_id
              AND EXISTS (
                  SELECT 1 FROM marketplace_inventory_cost_prices keep
                  WHERE keep.listing_id = m.keep_id
                    AND keep.effective_from = dup.effective_from
              )
        SQL);

        $this->addSql(<<<'SQL'
            DELETE FROM marketplace_listing_tag_assignments dup
            USING _wb_size_merge_map m
            WHERE dup.listing_id = m.dup_id
              AND EXISTS (
                  SELECT 1 FROM marketplace_listing_tag_assignments keep
                  WHERE keep.listing_id = m.keep_id
                    AND keep.tag_id = dup.tag_id
              )
        SQL);

        $this->addSql(<<<'SQL'
            DELETE FROM listing_daily_snapshots dup
            USING _wb_size_merge_map m
            WHERE dup.listing_id = m.dup_id
              AND EXISTS (
                  SELECT 1 FROM listing_daily_snapshots keep
                  WHERE keep.listing_id = m.keep_id
                    AND keep.company_id = dup.company_id
                    AND keep.snapshot_date = dup.snapshot_date
              )
        SQL);

        $this->addSql(<<<'SQL'
            DELETE FROM marketplace_advertising_costs dup
            USING _wb_size_merge_map m
            WHERE dup.listing_id = m.dup_id
              AND EXISTS (
                  SELECT 1 FROM marketplace_advertising_costs keep
                  WHERE keep.listing_id = m.keep_id
                    AND keep.company_id = dup.company_id
                    AND keep.date = dup.date
                    AND keep.advertising_type = dup.advertising_type
                    AND keep.external_campaign_id = dup.external_campaign_id
              )
        SQL);

        // ── 3. Рекламные строки: расход дубля переносим в строку канонического ──
        //    Уникальный ключ (ad_document_id, listing_id), поэтому простой UPDATE
        //    упал бы; удалять нельзя — потеряется рекламный расход документа.
        $this->addSql(<<<'SQL'
            UPDATE marketplace_ad_document_lines keep
            SET cost          = COALESCE(keep.cost, 0) + COALESCE(dup.cost, 0),
                share_percent = COALESCE(keep.share_percent, 0) + COALESCE(dup.share_percent, 0),
                impressions   = COALESCE(keep.impressions, 0) + COALESCE(dup.impressions, 0),
                clicks        = COALESCE(keep.clicks, 0) + COALESCE(dup.clicks, 0)
            FROM marketplace_ad_document_lines dup
            JOIN _wb_size_merge_map m ON m.dup_id = dup.listing_id
            WHERE keep.listing_id = m.keep_id
              AND keep.ad_document_id = dup.ad_document_id
        SQL);

        $this->addSql(<<<'SQL'
            DELETE FROM marketplace_ad_document_lines dup
            USING _wb_size_merge_map m
            WHERE dup.listing_id = m.dup_id
              AND EXISTS (
                  SELECT 1 FROM marketplace_ad_document_lines keep
                  WHERE keep.listing_id = m.keep_id
                    AND keep.ad_document_id = dup.ad_document_id
              )
        SQL);

        // ── 4. Переключение всех таблиц с listing_id на канонический листинг ──
        foreach ([
            'marketplace_sales',
            'marketplace_returns',
            'marketplace_costs',
            'marketplace_orders',
            'marketplace_staging',
            'marketplace_ozon_realizations',
            'marketplace_listing_barcodes',
            'marketplace_inventory_cost_prices',
            'marketplace_listing_tag_assignments',
            'marketplace_advertising_costs',
            'marketplace_ad_document_lines',
            'listing_daily_snapshots',
            'inventory_stock_snapshots',
            'ingest_financial_transactions',
        ] as $table) {
            $this->addSql(sprintf(
                'UPDATE %s t SET listing_id = m.keep_id FROM _wb_size_merge_map m WHERE t.listing_id = m.dup_id',
                $table,
            ));
        }

        // ── 5. Удаляем дубли ──
        $this->addSql('DELETE FROM marketplace_listings WHERE id IN (SELECT dup_id FROM _wb_size_merge_map)');

        // ── 6. Переносим идентичность дубля на канонический листинг ──
        $this->addSql(<<<'SQL'
            UPDATE marketplace_listings keep
            SET marketplace_variant_id = COALESCE(keep.marketplace_variant_id, m.dup_variant_id),
                product_id             = COALESCE(keep.product_id, m.dup_product_id),
                supplier_sku           = COALESCE(keep.supplier_sku, m.dup_supplier_sku),
                name                   = COALESCE(keep.name, m.dup_name)
            FROM _wb_size_merge_map m
            WHERE keep.id = m.keep_id
        SQL);

        // ── 7. Нормализуем оставшиеся size='0' (nm_id без второй половины) ──
        $this->addSql("UPDATE marketplace_listings SET size = 'UNKNOWN' WHERE marketplace = 'wildberries' AND size = '0'");

        $this->addSql('DROP TABLE _wb_size_merge_map');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Merge migration cannot be reversed: deleted duplicate listings and merged ad document lines are not recoverable.'
        );
    }
}
