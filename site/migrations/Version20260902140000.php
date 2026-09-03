<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Три отметки наблюдения вместо одной.
 *
 * Потоки маркетплейсов сообщают о заказе РАЗНОЕ, и сваливать это в одну
 * отметку нельзя:
 *
 * - `status_observed_at` — когда статус наблюдался. Становится nullable:
 *   заказ мог быть заведён наблюдением, которое о статусе молчит (поток
 *   изменений WB без отмены, ответ `/api/v3/orders` без строки статуса).
 *   Отметка такого наблюдения закрыла бы дорогу первому настоящему статусу,
 *   если тот окажется старше по времени скачивания.
 * - `snapshot_observed_at` — когда наблюдался полный состав заказа.
 * - `partial_observed_at` — когда наблюдалось частичное сообщение. Отдельная
 *   отметка нужна потому, что частичный поток обновляет своё — атрибуты,
 *   уточнение схемы, недостающие позиции, — и привязка к статусной отметке
 *   теряла бы все эти данные, когда статуса в наблюдении нет.
 */
final class Version20260902140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Splits ingest order observation watermarks into status, snapshot and partial.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_orders ALTER status_observed_at DROP NOT NULL');
        $this->addSql('ALTER TABLE ingest_orders ADD partial_observed_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN ingest_orders.partial_observed_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_orders DROP partial_observed_at');
        $this->addSql("UPDATE ingest_orders SET status_observed_at = COALESCE(status_observed_at, created_at)");
        $this->addSql('ALTER TABLE ingest_orders ALTER status_observed_at SET NOT NULL');
    }
}
