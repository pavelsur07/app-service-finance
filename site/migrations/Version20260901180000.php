<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ingest_orders, ingest_order_items and ingest_order_status_events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ingest_orders (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                connection_ref VARCHAR(255) NOT NULL,
                shop_ref VARCHAR(255) DEFAULT '' NOT NULL,
                source VARCHAR(255) NOT NULL,
                scheme VARCHAR(16) NOT NULL,
                external_id VARCHAR(255) NOT NULL,
                external_order_id VARCHAR(255) DEFAULT NULL,
                ordered_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                raw_status VARCHAR(255) NOT NULL,
                raw_substatus VARCHAR(255) DEFAULT NULL,
                status VARCHAR(32) NOT NULL,
                status_observed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_raw_record_id UUID DEFAULT NULL,
                refresh_stopped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                attributes JSON DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_ingest_order_external ON ingest_orders (company_id, source, external_id)');
        $this->addSql('CREATE INDEX idx_ingest_order_company_status_ordered ON ingest_orders (company_id, status, ordered_at)');
        $this->addSql('CREATE INDEX idx_ingest_order_company_connection ON ingest_orders (company_id, connection_ref)');
        foreach (['ordered_at', 'status_observed_at', 'refresh_stopped_at', 'created_at', 'updated_at'] as $column) {
            $this->addSql(sprintf("COMMENT ON COLUMN ingest_orders.%s IS '(DC2Type:datetime_immutable)'", $column));
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE ingest_order_items (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                order_id UUID NOT NULL,
                line_no INT NOT NULL,
                external_sku VARCHAR(100) DEFAULT NULL,
                offer_id VARCHAR(255) DEFAULT NULL,
                barcode VARCHAR(100) DEFAULT NULL,
                name VARCHAR(500) DEFAULT NULL,
                quantity INT NOT NULL,
                price_minor BIGINT DEFAULT NULL,
                currency VARCHAR(3) DEFAULT NULL,
                marketplace_buyout BOOLEAN DEFAULT false NOT NULL,
                listing_id UUID DEFAULT NULL,
                listing_sku VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        // Ключ идемпотентности: перенормализация того же raw обязана обновить
        // позиции, а не создать дубли. По external_sku нельзя — один SKU может
        // повториться на двух строках одного отправления.
        $this->addSql('CREATE UNIQUE INDEX uniq_ingest_order_item_line ON ingest_order_items (company_id, order_id, line_no)');
        $this->addSql('CREATE INDEX idx_ingest_order_item_company_listing ON ingest_order_items (company_id, listing_id)');
        $this->addSql('CREATE INDEX idx_ingest_order_item_order ON ingest_order_items (order_id)');
        foreach (['created_at', 'updated_at'] as $column) {
            $this->addSql(sprintf("COMMENT ON COLUMN ingest_order_items.%s IS '(DC2Type:datetime_immutable)'", $column));
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE ingest_order_status_events (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                order_id UUID NOT NULL,
                raw_status VARCHAR(255) NOT NULL,
                status VARCHAR(32) NOT NULL,
                previous_status VARCHAR(32) DEFAULT NULL,
                observed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                raw_record_id UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_ingest_order_status_event_order ON ingest_order_status_events (order_id, observed_at)');
        $this->addSql('CREATE INDEX idx_ingest_order_status_event_company ON ingest_order_status_events (company_id, observed_at)');
        foreach (['observed_at', 'created_at'] as $column) {
            $this->addSql(sprintf("COMMENT ON COLUMN ingest_order_status_events.%s IS '(DC2Type:datetime_immutable)'", $column));
        }

        // Внешних ключей на ingest_orders намеренно нет.
        //
        // Модуль ссылается на свои сущности скалярами, без ORM-связей, поэтому
        // Doctrine такие ключи не моделирует и предлагал бы их удалить при
        // каждом doctrine:schema:update. Вечно грязная разница схемы опаснее
        // отсутствия ключа: она маскирует настоящий дрейф.
        //
        // Целостность обеспечивается приложением: позиции и события создаются
        // только вместе с заказом, а заказы не удаляются (retention чистит
        // сырьё на S3, не нормализованные данные). Индексы по order_id
        // созданы выше и покрывают выборку потомков.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ingest_order_status_events');
        $this->addSql('DROP TABLE ingest_order_items');
        $this->addSql('DROP TABLE ingest_orders');
    }
}
