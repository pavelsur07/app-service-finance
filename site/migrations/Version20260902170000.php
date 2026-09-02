<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Признак «наблюдение сдвинуло состояние», уточнённый ключ наблюдения и индекс
 * под очередь перепроса.
 *
 * `applied` разделяет два смысла, которые были слиты. Наблюдение фиксируется
 * как факт даже когда оно устарело — сырьё пришло позже, чем более свежее, —
 * но переходом такое наблюдение не является. Записанное с
 * `previousStatus = DELIVERED` и `status = SHIPPED`, оно утверждало бы
 * движение заказа, которого не было.
 *
 * Колонка NULLABLE, а не `DEFAULT true`: для строк, записанных до её
 * появления, признак невосстановим. Среди них есть и устаревшие наблюдения,
 * которые заказ не применял, поэтому проставить им `true` значило бы задним
 * числом объявить переходами то, чего не было. NULL читается как «неизвестно»
 * и не врёт.
 *
 * `previous_status` входит в ключ наблюдения: одно сырьё может содержать
 * последовательность A → B → A, и без него третье наблюдение подавлялось бы
 * ключом первого — заказ вернулся бы в A, а журнал закончился бы на B.
 *
 * Индекс — под фактический предикат И порядок часовой очереди, включая
 * выражение `CASE`, которым «ни разу не спрошенные» поднимаются в начало.
 * Индекс без этого выражения не совпал бы с pathkeys запроса, и PostgreSQL всё
 * равно сортировал бы всех кандидатов подключения. Частичный по
 * `refresh_stopped_at IS NULL`: остановленные заказы в очередь не попадают
 * никогда.
 */
final class Version20260902170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds an applied flag, widens the observation key and indexes the refresh queue.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_order_status_events ADD applied BOOLEAN DEFAULT NULL');

        $this->addSql('DROP INDEX uniq_ingest_order_status_event_observation');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_ingest_order_status_event_observation
             ON ingest_order_status_events (company_id, raw_record_id, order_id, raw_status, previous_status)'
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
             WHERE refresh_stopped_at IS NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ingest_order_refresh_queue');

        $this->addSql('DROP INDEX uniq_ingest_order_status_event_observation');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_ingest_order_status_event_observation
             ON ingest_order_status_events (company_id, raw_record_id, order_id, raw_status)'
        );

        $this->addSql('ALTER TABLE ingest_order_status_events DROP applied');
    }
}
