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
 * Backfill: заказам, которые ещё могут попасть в очередь, отметка попытки
 * приравнивается к отметке наблюдения. NULL означал бы «ни разу не пытались» и
 * поставил бы весь накопленный объём в начало очереди разом. Терминальные и
 * уже остановленные заказы не трогаются: они в очередь не попадают, и
 * переписывать их значило бы платить длинной транзакцией за строки, которые
 * эту колонку никогда не прочитают.
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
        // Backfill только там, где отметка вообще пригодится: в очередь
        // перепроса попадают лишь нетерминальные и не остановленные заказы.
        // Переписывать всю таблицу целиком значило бы на накопленных данных
        // создать долгую транзакцию и большой WAL ради строк, которые эту
        // колонку никогда не прочитают.
        //
        // На момент миграции таблицы заказов в production пусты — заказы
        // вводятся этой же задачей, — поэтому здесь это мгновенно. Условие
        // оставлено ради повторного применения на непустой базе.
        $this->addSql(
            "UPDATE ingest_orders
                SET status_refresh_attempted_at = status_observed_at
              WHERE status_observed_at IS NOT NULL
                AND refresh_stopped_at IS NULL
                AND status NOT IN ('delivered', 'cancelled', 'returned')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_orders DROP status_refresh_attempted_at');
    }
}
