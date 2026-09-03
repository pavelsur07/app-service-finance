<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Индексы retention строятся КОНКУРЕНТНО.
 *
 * `ingest_raw_records` — горячая таблица: в неё пишет каждая загрузка, и
 * часовой опрос обновляет `last_seen_at`. Обычный `CREATE INDEX` держит блок
 * на запись всё время построения, то есть остановил бы ingestion на время
 * сканирования таблицы.
 *
 * `CREATE INDEX CONCURRENTLY` не работает внутри транзакции, поэтому миграция
 * объявлена нетранзакционной и вынесена отдельно от DDL колонок. Цена —
 * возможный `INVALID` индекс при неудачном построении: его нужно удалить и
 * повторить, обычным `DROP INDEX` он не мешает.
 */
final class Version20260902190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Builds retention indexes concurrently on the hot raw records table.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Неудачное конкурентное построение оставляет INVALID-индекс.
        // `IF NOT EXISTS` считал бы его существующим и молча пропустил: индекса
        // фактически нет, а миграция числится применённой. Поэтому сначала
        // снимаем невалидный, а `IF NOT EXISTS` оставляем ради повторяемости:
        // миграция нетранзакционная, и второй прогон не должен падать на уже
        // построенном ВАЛИДНОМ индексе.
        $this->dropInvalid('idx_ingest_raw_record_retention');
        $this->dropInvalid('idx_ingest_raw_record_pending_deletion');

        // Кандидаты на решение: ещё не помеченные и давно не встречавшиеся.
        $this->addSql(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ingest_raw_record_retention
             ON ingest_raw_records (last_seen_at)
             WHERE payload_pruned_at IS NULL'
        );

        // Незавершённая очистка: решение принято, объект ещё не удалён.
        $this->addSql(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ingest_raw_record_pending_deletion
             ON ingest_raw_records (payload_deletion_attempted_at)
             WHERE payload_pruned_at IS NOT NULL AND payload_deleted_at IS NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_ingest_raw_record_pending_deletion');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_ingest_raw_record_retention');
    }

    /**
     * Снять индекс, если он существует и НЕВАЛИДЕН.
     *
     * Валидный не трогаем: повторный прогон миграции не должен ронять рабочий
     * индекс и перестраивать его впустую.
     */
    private function dropInvalid(string $indexName): void
    {
        $invalid = (bool) $this->connection->fetchOne(
            'SELECT EXISTS (
                 SELECT 1 FROM pg_class c
                 JOIN pg_index i ON i.indexrelid = c.oid
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE c.relname = :name
                   AND n.nspname = current_schema()
                   AND NOT i.indisvalid
             )',
            ['name' => $indexName],
        );

        if ($invalid) {
            $this->addSql(sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $indexName));
        }
    }
}
