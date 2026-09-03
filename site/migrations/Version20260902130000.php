<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Микросекундная точность отметок наблюдения и заполнение водяного знака
 * снимка у ранее заведённых заказов.
 *
 * Точность. Сущность объявляет `precision: 6`, а колонки создавались как
 * TIMESTAMP(0). `doctrine:schema:update` это расхождение не показывает — он не
 * диффит точность, поэтому зелёная проверка схемы ничего о нём не говорила.
 *
 * Оговорка, чтобы не создавать ложного впечатления: сами микросекунды этим НЕ
 * начинают сохраняться. Стандартный тип `datetime_immutable` пишет
 * `Y-m-d H:i:s` независимо от точности колонки, поэтому сравнение отметок
 * наблюдения остаётся посекундным, а два наблюдения внутри одной секунды
 * считаются одновременными. Приведение типа колонки убирает расхождение с
 * объявленной моделью и даёт куда писать, если микросекундный тип появится;
 * сам такой тип — правка, задевающая 55 колонок по всему проекту, и она за
 * пределами этой стадии.
 *
 * Заполнение. `snapshot_observed_at` у существующих строк остался NULL, а по
 * NULL снимок принимался бы любой давности. До появления колонки все
 * наблюдения были полными снимками и пользовались `status_observed_at` —
 * она и есть верная граница.
 *
 * Отдельной миграцией, а не правкой предыдущей: та уже зарегистрирована, и
 * Doctrine не выполняет её повторно.
 */
final class Version20260902130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aligns ingest order timestamp precision with the entity model and backfills the snapshot watermark.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_orders ALTER status_observed_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE ingest_orders ALTER snapshot_observed_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE ingest_order_status_events ALTER observed_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE');
        $this->addSql('UPDATE ingest_orders SET snapshot_observed_at = status_observed_at WHERE snapshot_observed_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_orders ALTER status_observed_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE ingest_orders ALTER snapshot_observed_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE ingest_order_status_events ALTER observed_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
    }
}
