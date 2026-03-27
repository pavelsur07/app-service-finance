<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Дедупликация marketplace_listings + гарантированный уникальный индекс.
 *
 * Причина дублей:
 *   Колонка size была добавлена миграцией 20260318160000 со значением DEFAULT NULL.
 *   PostgreSQL не считает NULL = NULL в уникальных индексах, поэтому строки
 *   (company_id, OZON, sku, NULL) и (company_id, OZON, sku, 'UNKNOWN') не конфликтовали.
 *   За окно между миграциями 20260318160000 и 20260321160000 параллельные импорты
 *   Sales / Returns / Costs создавали дублирующие листинги для одного SKU.
 *   Если миграция 20260321160000 упала из-за уже существующих дублей — уникальный
 *   индекс так и не был создан, и дубли продолжали накапливаться.
 *
 * Что делает эта миграция:
 *   1. Для каждой группы дублей определяет канонический листинг (наиболее старый по created_at).
 *   2. Переключает все FK-ссылки (sales, returns, costs, ozon_realizations, staging,
 *      inventory_cost_prices, barcodes) на канонический листинг.
 *   3. Удаляет дублирующие записи.
 *   4. Пересоздаёт уникальный индекс.
 */
final class Version20260331120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deduplicate marketplace_listings and enforce unique index (fix concurrent-import duplicates)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('marketplace_listings')) {
            return;
        }

        // ── 1. Нормализуем NULL → 'UNKNOWN' (на случай если предыдущая миграция не выполнилась) ──
        $this->addSql("UPDATE marketplace_listings SET size = 'UNKNOWN' WHERE size IS NULL");

        // ── 2. Переключаем FK: marketplace_sales ──
        $this->addSql(<<<'SQL'
            UPDATE marketplace_sales ms
            SET listing_id = canon.keep_id
            FROM (
                SELECT
                    id AS dup_id,
                    FIRST_VALUE(id) OVER (
                        PARTITION BY company_id, marketplace, marketplace_sku, size
                        ORDER BY created_at ASC, id ASC
                    ) AS keep_id
                FROM marketplace_listings
            ) canon
            WHERE ms.listing_id = canon.dup_id
              AND canon.dup_id <> canon.keep_id
        SQL);

        // ── 3. Переключаем FK: marketplace_returns ──
        $this->addSql(<<<'SQL'
            UPDATE marketplace_returns mr
            SET listing_id = canon.keep_id
            FROM (
                SELECT
                    id AS dup_id,
                    FIRST_VALUE(id) OVER (
                        PARTITION BY company_id, marketplace, marketplace_sku, size
                        ORDER BY created_at ASC, id ASC
                    ) AS keep_id
                FROM marketplace_listings
            ) canon
            WHERE mr.listing_id = canon.dup_id
              AND canon.dup_id <> canon.keep_id
        SQL);

        // ── 4. Переключаем FK: marketplace_costs ──
        $this->addSql(<<<'SQL'
            UPDATE marketplace_costs mc
            SET listing_id = canon.keep_id
            FROM (
                SELECT
                    id AS dup_id,
                    FIRST_VALUE(id) OVER (
                        PARTITION BY company_id, marketplace, marketplace_sku, size
                        ORDER BY created_at ASC, id ASC
                    ) AS keep_id
                FROM marketplace_listings
            ) canon
            WHERE mc.listing_id = canon.dup_id
              AND canon.dup_id <> canon.keep_id
        SQL);

        // ── 5. Переключаем FK: marketplace_ozon_realizations ──
        $this->addSql(<<<'SQL'
            UPDATE marketplace_ozon_realizations mor
            SET listing_id = canon.keep_id
            FROM (
                SELECT
                    id AS dup_id,
                    FIRST_VALUE(id) OVER (
                        PARTITION BY company_id, marketplace, marketplace_sku, size
                        ORDER BY created_at ASC, id ASC
                    ) AS keep_id
                FROM marketplace_listings
            ) canon
            WHERE mor.listing_id = canon.dup_id
              AND canon.dup_id <> canon.keep_id
        SQL);

        // ── 6. Переключаем FK: marketplace_staging ──
        $this->addSql(<<<'SQL'
            UPDATE marketplace_staging mst
            SET listing_id = canon.keep_id
            FROM (
                SELECT
                    id AS dup_id,
                    FIRST_VALUE(id) OVER (
                        PARTITION BY company_id, marketplace, marketplace_sku, size
                        ORDER BY created_at ASC, id ASC
                    ) AS keep_id
                FROM marketplace_listings
            ) canon
            WHERE mst.listing_id = canon.dup_id
              AND canon.dup_id <> canon.keep_id
        SQL);

        // ── 7. marketplace_inventory_cost_prices: удаляем конфликтующие строки дубля ──
        //    (у canonical листинга уже может быть запись на ту же дату)
        $this->addSql(<<<'SQL'
            DELETE FROM marketplace_inventory_cost_prices icp
            USING (
                SELECT
                    id AS dup_id,
                    FIRST_VALUE(id) OVER (
                        PARTITION BY company_id, marketplace, marketplace_sku, size
                        ORDER BY created_at ASC, id ASC
                    ) AS keep_id
                FROM marketplace_listings
            ) canon
            WHERE icp.listing_id = canon.dup_id
              AND canon.dup_id <> canon.keep_id
              AND EXISTS (
                  SELECT 1 FROM marketplace_inventory_cost_prices existing
                  WHERE existing.listing_id = canon.keep_id
                    AND existing.effective_from = icp.effective_from
              )
        SQL);

        // ── 8. marketplace_inventory_cost_prices: переключаем оставшиеся ──
        $this->addSql(<<<'SQL'
            UPDATE marketplace_inventory_cost_prices icp
            SET listing_id = canon.keep_id
            FROM (
                SELECT
                    id AS dup_id,
                    FIRST_VALUE(id) OVER (
                        PARTITION BY company_id, marketplace, marketplace_sku, size
                        ORDER BY created_at ASC, id ASC
                    ) AS keep_id
                FROM marketplace_listings
            ) canon
            WHERE icp.listing_id = canon.dup_id
              AND canon.dup_id <> canon.keep_id
        SQL);

        // ── 9. marketplace_listing_barcodes: удаляем конфликтующие ──
        $this->addSql(<<<'SQL'
            DELETE FROM marketplace_listing_barcodes lb
            USING (
                SELECT
                    id AS dup_id,
                    FIRST_VALUE(id) OVER (
                        PARTITION BY company_id, marketplace, marketplace_sku, size
                        ORDER BY created_at ASC, id ASC
                    ) AS keep_id
                FROM marketplace_listings
            ) canon,
            marketplace_listing_barcodes lb2
            WHERE lb.listing_id = canon.dup_id
              AND canon.dup_id <> canon.keep_id
              AND lb2.listing_id = canon.keep_id
              AND lb2.barcode = lb.barcode
        SQL);

        // ── 10. marketplace_listing_barcodes: переключаем оставшиеся ──
        $this->addSql(<<<'SQL'
            UPDATE marketplace_listing_barcodes lb
            SET listing_id = canon.keep_id
            FROM (
                SELECT
                    id AS dup_id,
                    FIRST_VALUE(id) OVER (
                        PARTITION BY company_id, marketplace, marketplace_sku, size
                        ORDER BY created_at ASC, id ASC
                    ) AS keep_id
                FROM marketplace_listings
            ) canon
            WHERE lb.listing_id = canon.dup_id
              AND canon.dup_id <> canon.keep_id
        SQL);

        // ── 11. Удаляем дублирующие листинги ──
        $this->addSql(<<<'SQL'
            DELETE FROM marketplace_listings
            WHERE id IN (
                SELECT dup_id FROM (
                    SELECT
                        id AS dup_id,
                        FIRST_VALUE(id) OVER (
                            PARTITION BY company_id, marketplace, marketplace_sku, size
                            ORDER BY created_at ASC, id ASC
                        ) AS keep_id
                    FROM marketplace_listings
                ) sub
                WHERE dup_id <> keep_id
            )
        SQL);

        // ── 12. Пересоздаём уникальный индекс (на случай если предыдущий не создался) ──
        $this->addSql('DROP INDEX IF EXISTS uniq_company_marketplace_sku_size');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_company_marketplace_sku_size
                ON marketplace_listings (company_id, marketplace, marketplace_sku, size)
        SQL);

        // ── 13. Устанавливаем NOT NULL + DEFAULT 'UNKNOWN' (если ещё не сделано) ──
        $this->addSql("ALTER TABLE marketplace_listings ALTER COLUMN size SET DEFAULT 'UNKNOWN'");
        $this->addSql('ALTER TABLE marketplace_listings ALTER COLUMN size SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Откат невозможен: удалённые дубли не восстановить.
        $this->throwIrreversibleMigrationException(
            'Deduplication migration cannot be reversed: deleted duplicate listings are not recoverable.'
        );
    }
}
