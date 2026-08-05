<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Закрепляет инвариант «одно активное правило на источник суммы» индексом БД.
 *
 * Существующий uniq_sale_mapping включает pl_category_id, поэтому два активных
 * правила на один amount_source с разными категориями ОПиУ он пропускает.
 * До сих пор инвариант держался только кодом (`deactivateOtherActive` в
 * контроллере, `NOT EXISTS` в DefaultSaleMappingWriter), а под конкурентными
 * запросами оба пути проверяют состояние до коммита соседа и расходятся.
 * Двойное активное правило означает две строки ОПиУ по одному источнику —
 * прямое искажение выручки, поэтому проверка переносится в схему.
 *
 * Частичный индекс: неактивные правила остаются историей и не мешают.
 *
 * Существующие данные проверены до написания миграции — нарушений нет
 * (SELECT ... WHERE is_active GROUP BY company_id, marketplace, operation_type,
 * amount_source HAVING count(*) > 1 вернул 0 строк), поэтому очистка данных
 * не требуется и миграция не удаляет и не изменяет ни одной строки.
 * Штатные пути записи уже совместимы: create/edit/toggle деактивируют
 * предыдущее активное правило до вставки.
 */
final class Version20260805090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add partial unique index enforcing a single active sale mapping per amount source';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_active_sale_mapping_source
            ON marketplace_sale_mappings (company_id, marketplace, operation_type, amount_source)
            WHERE is_active
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_active_sale_mapping_source');
    }
}
