<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Исправляет уникальный индекс таблицы marketplace_listing_barcodes.
 *
 * Проблема: uniq_company_barcode (company_id, barcode) не учитывал маркетплейс.
 * Один и тот же баркод может быть зарегистрирован на Ozon и WB одновременно —
 * при импорте второй площадки возникал UNIQUE violation:
 * "Дубликат товара! Поля: company_id, barcode".
 *
 * Решение:
 *   1. Добавляем колонку marketplace.
 *   2. Заполняем значениями из связанной таблицы marketplace_listings.
 *   3. Удаляем старый индекс (company_id, barcode).
 *   4. Создаём новый (company_id, marketplace, barcode).
 */
final class Version20260401000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Добавить marketplace в marketplace_listing_barcodes, исправить уникальный индекс';
    }

    public function up(Schema $schema): void
    {
        // 1. Добавляем колонку с временным DEFAULT для существующих строк
        $this->addSql("ALTER TABLE marketplace_listing_barcodes ADD marketplace VARCHAR(50) NOT NULL DEFAULT 'WILDBERRIES'");

        // 2. Заполняем marketplace из связанного листинга
        $this->addSql('
            UPDATE marketplace_listing_barcodes b
            SET marketplace = l.marketplace
            FROM marketplace_listings l
            WHERE b.listing_id = l.id
        ');

        // 3. Убираем временный DEFAULT — значение теперь передаётся явно при вставке
        $this->addSql('ALTER TABLE marketplace_listing_barcodes ALTER COLUMN marketplace DROP DEFAULT');

        // 4. Удаляем старый неправильный индекс без учёта маркетплейса
        $this->addSql('DROP INDEX IF EXISTS uniq_company_barcode');

        // 5. Создаём правильный уникальный индекс: один баркод уникален внутри маркетплейса компании
        $this->addSql('
            CREATE UNIQUE INDEX uniq_company_marketplace_barcode
            ON marketplace_listing_barcodes (company_id, marketplace, barcode)
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_company_marketplace_barcode');
        $this->addSql('ALTER TABLE marketplace_listing_barcodes DROP COLUMN marketplace');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_barcode ON marketplace_listing_barcodes (company_id, barcode)');
    }
}
