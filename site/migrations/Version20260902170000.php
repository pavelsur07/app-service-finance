<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Признак «наблюдение сдвинуло состояние», порядковый номер наблюдения и
 * индекс под очередь перепроса.
 *
 * `applied` разделяет два смысла, которые были слиты. Наблюдение фиксируется
 * как факт даже когда оно устарело — сырьё пришло позже, чем более свежее, —
 * но переходом такое наблюдение не является. Записанное с
 * `previousStatus = DELIVERED` и `status = SHIPPED`, оно утверждало бы
 * движение заказа, которого не было. Колонка NULLABLE: для строк, записанных
 * до её появления, признак невосстановим, и `DEFAULT true` объявил бы
 * переходами то, чего не было.
 *
 * `occurrence` заменяет содержательный ключ наблюдения порядковым номером
 * внутри пары (сырьё, заказ). Ключ по содержанию не работает: одно сырьё может
 * содержать A → B → A → B, и любой набор колонок статуса подавляет одно из
 * повторяющихся наблюдений — заказ применяет переход, а журнал его теряет.
 * Порядковый номер различает наблюдения по факту их появления, а от повторной
 * записи защищает не ключ, а транзакция разбора и отдельный путь повтора
 * (`OrderStatusJournal::reapply()`). Уже записанным событиям номера
 * проставляются оконной функцией ДО создания индекса: прежняя схема допускала
 * несколько строк на пару (сырьё, заказ), и общий ноль уронил бы миграцию на
 * первой же настоящей истории A → B.
 *
 * Индекс очереди — под фактический предикат И порядок, включая выражение
 * `CASE`, которым «ни разу не спрошенные» поднимаются в начало: без него
 * pathkeys не совпадают и PostgreSQL сортирует всех кандидатов подключения.
 * Терминальные статусы исключены из индекса: у заказа, созданного сразу
 * доставленным, отметка попытки навсегда NULL, и он занимал бы начало индекса,
 * хотя запрос отбрасывает его следом. Список статусов записан литералами —
 * значения enum'а, а не параметры, иначе PostgreSQL не смог бы доказать, что
 * предикат запроса влечёт предикат индекса.
 *
 * Про блокировки: таблицы заказов в production пусты — заказы этой задачей
 * только вводятся, — поэтому перестроение индекса здесь мгновенно и окна
 * обслуживания не требует. Для непустой таблицы этот же DDL пришлось бы
 * строить конкурентно.
 */
final class Version20260902170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds an applied flag and an occurrence ordinal to order status events, indexes the refresh queue.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_order_status_events ADD applied BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE ingest_order_status_events ADD occurrence INT DEFAULT 0 NOT NULL');

        // Существующие события нумеруются ДО создания уникального индекса.
        //
        // Прежняя схема допускала несколько строк на пару (сырьё, заказ) —
        // ровно так выглядит записанная история A → B. Оставить им общий ноль
        // значило бы уронить создание индекса на первой же настоящей истории.
        // Порядок — по времени наблюдения, при равенстве по id: он устойчив и
        // повторяем.
        $this->addSql(
            'UPDATE ingest_order_status_events AS e
                SET occurrence = numbered.position
               FROM (
                   SELECT id,
                          ROW_NUMBER() OVER (
                              PARTITION BY company_id, raw_record_id, order_id
                              ORDER BY observed_at, id
                          ) - 1 AS position
                     FROM ingest_order_status_events
               ) AS numbered
              WHERE e.id = numbered.id'
        );

        $this->addSql('DROP INDEX uniq_ingest_order_status_event_observation');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_ingest_order_status_event_observation
             ON ingest_order_status_events (company_id, raw_record_id, order_id, occurrence)'
        );

        $this->addSql(
            'CREATE INDEX idx_ingest_order_refresh_queue
             ON ingest_orders (
                 company_id,
                 source,
                 connection_ref,
                 (CASE WHEN status_refresh_attempted_at IS NULL THEN 0 ELSE 1 END),
                 status_refresh_attempted_at,
                 id
             )
             WHERE refresh_stopped_at IS NULL
               AND status NOT IN (\'delivered\', \'cancelled\', \'returned\')'
        );
    }

    public function down(Schema $schema): void
    {
        // Проверка ДО любого DDL. Старый уникальный индекс по
        // (company, raw, order, raw_status) не выдержит данных, которые
        // разрешает новый: законная последовательность A → B → A даёт две
        // строки с одинаковым raw_status. Обнаружить это после удаления
        // индексов значило бы оставить схему разобранной.
        $conflicting = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (
                 SELECT 1 FROM ingest_order_status_events
                 GROUP BY company_id, raw_record_id, order_id, raw_status
                 HAVING COUNT(*) > 1
             ) AS duplicates'
        );

        $this->abortIf(
            $conflicting > 0,
            sprintf(
                'Rollback would recreate a unique index that %d existing observation group(s) violate. Decide how to collapse them first.',
                $conflicting,
            ),
        );

        $this->addSql('DROP INDEX idx_ingest_order_refresh_queue');

        $this->addSql('DROP INDEX uniq_ingest_order_status_event_observation');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_ingest_order_status_event_observation
             ON ingest_order_status_events (company_id, raw_record_id, order_id, raw_status)'
        );

        $this->addSql('ALTER TABLE ingest_order_status_events DROP occurrence');
        $this->addSql('ALTER TABLE ingest_order_status_events DROP applied');
    }
}
