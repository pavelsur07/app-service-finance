<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Отметка ПОПЫТКИ перепроса статуса — отдельно от отметки наблюдения.
 *
 * Очередь перепроса планировалась по `status_observed_at`, но попытка бывает
 * без наблюдения: Ozon отвечает 404 на неизвестный номер, отправление
 * приходит без поля статуса, заказ отсутствует в успешном ответе WB. Такие
 * заказы отметку не двигают, а сортировка стабильна — значит они вечно
 * занимают начало лимита, и остальные заказы кабинета не опрашиваются
 * никогда, попадая сразу в STUCK_ORDER.
 *
 * Backfill: существующим заказам отметка попытки приравнивается к отметке
 * наблюдения. NULL означал бы «ни разу не пытались» и поставил бы весь
 * накопленный объём в начало очереди разом.
 */
final class Version20260902150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds a status refresh attempt watermark to ingest orders.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_orders ADD status_refresh_attempted_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN ingest_orders.status_refresh_attempted_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('UPDATE ingest_orders SET status_refresh_attempted_at = status_observed_at WHERE status_observed_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_orders DROP status_refresh_attempted_at');
    }
}
