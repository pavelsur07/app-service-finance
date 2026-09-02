<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Отметки наблюдения переходят на тип, действительно хранящий микросекунды.
 *
 * Колонки уже были `TIMESTAMP(6)`, но стандартный `datetime_immutable`
 * форматирует значение как `Y-m-d H:i:s` независимо от точности колонки, и
 * микросекунды терялись при записи. Два наблюдения внутри одной секунды
 * становились неразличимы, а `observeStatus()` принимает наблюдение «не
 * старше» текущего — поэтому наблюдение `12:00:00.900`, сохранённое как
 * `12:00:00`, проигрывало более СТАРОМУ `12:00:00.100`, обработанному позже:
 * статус ехал назад, а в журнале появлялся перевёрнутый переход.
 *
 * DDL меняется только в комментариях: тип колонки в PostgreSQL уже верный,
 * Doctrine отличает свои типы по метке `(DC2Type:...)`. Существующие значения
 * второй точности читаются новым типом без конверсии.
 */
final class Version20260902160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Switches ingest order observation watermarks to a microsecond-preserving type.';
    }

    public function up(Schema $schema): void
    {
        foreach (['status_observed_at', 'snapshot_observed_at', 'partial_observed_at', 'status_refresh_attempted_at'] as $column) {
            $this->addSql(sprintf("COMMENT ON COLUMN ingest_orders.%s IS '(DC2Type:datetime_immutable_us)'", $column));
        }

        $this->addSql("COMMENT ON COLUMN ingest_order_status_events.observed_at IS '(DC2Type:datetime_immutable_us)'");

        // fetched_at — момент наблюдения для нормализации: без микросекунд
        // здесь отметки заказа получают их уже усечёнными, и вся точность
        // выше по течению теряет смысл.
        $this->addSql("COMMENT ON COLUMN ingest_raw_records.fetched_at IS '(DC2Type:datetime_immutable_us)'");
    }

    public function down(Schema $schema): void
    {
        foreach (['status_observed_at', 'snapshot_observed_at', 'partial_observed_at', 'status_refresh_attempted_at'] as $column) {
            $this->addSql(sprintf("COMMENT ON COLUMN ingest_orders.%s IS '(DC2Type:datetime_immutable)'", $column));
        }

        $this->addSql("COMMENT ON COLUMN ingest_order_status_events.observed_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN ingest_raw_records.fetched_at IS '(DC2Type:datetime_immutable)'");
    }
}
