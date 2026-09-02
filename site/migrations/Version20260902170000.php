<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Признак «наблюдение сдвинуло состояние» у события журнала и индекс под
 * очередь перепроса.
 *
 * `applied` разделяет два смысла, которые до сих пор были слиты. Наблюдение
 * фиксируется как факт даже когда оно устарело — сырьё пришло позже, чем более
 * свежее, — но переходом такое наблюдение не является. Записанное с
 * `previousStatus = DELIVERED` и `status = SHIPPED`, оно утверждало бы
 * движение заказа, которого не было.
 *
 * Индекс — под фактический предикат и порядок часовой очереди. LIMIT
 * ограничивает результат, но не число строк, которые PostgreSQL обязан
 * отфильтровать и отсортировать для каждого подключения. Частичный по
 * `refresh_stopped_at IS NULL`: остановленные заказы в очередь не попадают
 * никогда, и держать их в индексе незачем.
 */
final class Version20260902170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds an applied flag to order status events and an index for the refresh queue.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_order_status_events ADD applied BOOLEAN DEFAULT true NOT NULL');

        $this->addSql(
            'CREATE INDEX idx_ingest_order_refresh_queue
             ON ingest_orders (company_id, source, connection_ref, status_refresh_attempted_at, id)
             WHERE refresh_stopped_at IS NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ingest_order_refresh_queue');
        $this->addSql('ALTER TABLE ingest_order_status_events DROP applied');
    }
}
