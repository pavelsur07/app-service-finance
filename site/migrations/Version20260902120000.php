<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Отметка последнего ПОЛНОГО снимка заказа.
 *
 * Отдельная миграция, а не правка Version20260901180000: та уже
 * зарегистрирована, и Doctrine не выполняет её повторно. База, мигрированная
 * до этого коммита, новую колонку не получила бы, а сущность начала бы к ней
 * обращаться — чтение и нормализация заказов сломались бы. Проверено на своей
 * шкуре: `migrations:migrate` отвечал «Already at the latest version», и
 * тестовую БД пришлось пересоздавать.
 *
 * Смысл колонки: свежесть статуса и свежесть снимка — разные вещи. Потоки WB
 * приходят вперемешку, и частичное наблюдение, скачанное позже, а разобранное
 * раньше, на одной отметке навсегда закрыло бы дорогу авторитетному снимку.
 */
final class Version20260902120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds ingest_orders.snapshot_observed_at to track the last authoritative order snapshot.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_orders ADD snapshot_observed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN ingest_orders.snapshot_observed_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_orders DROP snapshot_observed_at');
    }
}
