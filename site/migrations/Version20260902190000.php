<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;

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
        //
        // Ключи индекса повторяют ORDER BY выборки ОДИН В ОДИН, вместе с
        // выражением `CASE`.
        //
        // Без него планировщик не сопоставляет сортировку с индексом: он не
        // знает, что `CASE WHEN attempted IS NULL THEN 0 ELSE 1 END, attempted`
        // — это то же самое, что `attempted NULLS FIRST`. Проверено на 200 000
        // помеченных записей: индекс по одной колонке не использовался вовсе,
        // PostgreSQL брал Parallel Seq Scan и top-N сортировку — 5899 буферов
        // и 40 мс, то есть отбирал и сортировал ВЕСЬ backlog до применения
        // `LIMIT`, ровно тогда, когда backlog велик: при массовом сбое
        // хранилища. С выражением в индексе тот же запрос идёт Index Scan'ом:
        // 28 буферов и 0,2 мс. Именно Index Scan, а не Index Only: ORM
        // гидратирует сущность целиком, поэтому обращение к таблице
        // неизбежно — индекс здесь даёт порядок и лимит, а не покрытие.
        // Замерялся SQL той формы, которую строит Doctrine, а не упрощённый.
        $this->addSql(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ingest_raw_record_pending_deletion
             ON ingest_raw_records (
                 (CASE WHEN payload_deletion_attempted_at IS NULL THEN 0 ELSE 1 END),
                 payload_deletion_attempted_at,
                 id
             )
             WHERE payload_pruned_at IS NOT NULL AND payload_deleted_at IS NULL'
        );
    }

    /**
     * Откат — СВОЯ транзакция под `ACCESS EXCLUSIVE`, и без `CONCURRENTLY`.
     *
     * Асимметрия с `up()` намеренная. Долгая операция — построение индекса,
     * ради неё и нужен `CONCURRENTLY`: он не держит запись, пока читает всю
     * горячую таблицу. Удаление индекса — операция над метаданными, её
     * блокировка коротка, и платить за `CONCURRENTLY` здесь нечем, кроме как
     * невозможностью проверить условие.
     *
     * А проверить нужно. Та же проверка, что и в
     * `Version20260902180000::down()`, но раньше по времени: откат идёт от
     * новых миграций к старым, поэтому эта снимает индексы ПЕРВОЙ. Если
     * следующая упрётся в необратимое состояние и прервётся, горячая таблица
     * уже останется без retention-индексов — откат провалился, а вред нанесён.
     *
     * Проверка и удаление обязаны быть ОДНИМ решением. Миграция
     * нетранзакционная, и между `COUNT(*)` и `DROP` конкурентный `--execute`
     * успевал закоммитить отметку: проверка говорила «ноль», индексы уходили,
     * а следующая миграция прерывалась уже после этого. `ACCESS EXCLUSIVE`
     * закрывает окно, а с `CONCURRENTLY` его закрыть нечем — оно запрещено в
     * транзакции.
     */
    public function down(Schema $schema): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('LOCK TABLE ingest_raw_records IN ACCESS EXCLUSIVE MODE');

            $pruned = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ingest_raw_records WHERE payload_pruned_at IS NOT NULL'
            );

            if ($pruned > 0) {
                $this->connection->rollBack();

                $this->abortIf(
                    true,
                    sprintf(
                        'Rollback would erase the only evidence that %d payload(s) were pruned on purpose. Decide what to do with them first.',
                        $pruned,
                    ),
                );

                return;
            }

            $this->connection->executeStatement('DROP INDEX IF EXISTS idx_ingest_raw_record_pending_deletion');
            $this->connection->executeStatement('DROP INDEX IF EXISTS idx_ingest_raw_record_retention');

            $this->connection->commit();
        } catch (AbortMigration $abort) {
            throw $abort;
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
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
