<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Порядок ПРИМЕНЕНИЯ событий журнала внутри заказа.
 *
 * История отдаётся по времени наблюдения, но два наблюдения могут прийтись на
 * одну микросекунду — например, из двух разных сырьевых записей. Тай-брейк
 * нужен настоящий: сортировка по `raw_record_id` не годится вовсе (сырьё
 * получает идентификатор при загрузке, задолго до разбора и другим процессом),
 * а по `id` события — только на вид: UUID v7 упорядочен по миллисекунде, и два
 * процесса, взявшие блокировку заказа внутри одной миллисекунды, могли дать
 * порядок, обратный порядку применения. Тогда цепочка `previous_status` не
 * сходится, а последним в истории оказывается не тот переход, который стоит в
 * заказе.
 *
 * Поэтому номер выдаёт САМ ЗАКАЗ: счётчик `ingest_orders.status_event_seq`
 * увеличивается под `PESSIMISTIC_WRITE` на строку заказа — ровно там, где
 * журнал и пишется. Два процесса получают номера по очереди, а не
 * одновременно.
 *
 * Счётчик именно у заказа, а не глобальная последовательность: монотонность
 * нужна в пределах одного заказа, а общая последовательность добавила бы
 * точку соперничества между всеми компаниями сразу.
 */
final class Version20260902200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds a per-order application ordinal to order status events.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_order_status_events ADD recorded_seq INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE ingest_orders ADD status_event_seq INT DEFAULT 0 NOT NULL');

        // Историю нумеруем ПО ЗАКАЗУ и в том порядке, в котором она читалась
        // до сих пор: по времени наблюдения, затем по идентификатору. Нулём
        // оставлять нельзя — тогда у всех старых событий заказа тай-брейк
        // одинаков, и порядок снова оказался бы произвольным.
        $this->addSql(
            'UPDATE ingest_order_status_events AS e
                SET recorded_seq = numbered.position
               FROM (
                   SELECT id,
                          ROW_NUMBER() OVER (PARTITION BY order_id ORDER BY observed_at, id) AS position
                     FROM ingest_order_status_events
               ) AS numbered
              WHERE e.id = numbered.id'
        );

        // Счётчик заказа обязан продолжать историю, а не начинать заново:
        // иначе следующее событие получило бы номер, уже занятый старым, и
        // порядок внутри одной микросекунды опять стал бы неопределённым.
        $this->addSql(
            'UPDATE ingest_orders AS o
                SET status_event_seq = COALESCE(
                    (SELECT MAX(e.recorded_seq) FROM ingest_order_status_events AS e WHERE e.order_id = o.id),
                    0
                )'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_orders DROP status_event_seq');
        $this->addSql('ALTER TABLE ingest_order_status_events DROP recorded_seq');
    }
}
