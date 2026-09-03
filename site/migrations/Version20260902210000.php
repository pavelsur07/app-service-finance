<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Владелец снимка и владелец частичного наблюдения.
 *
 * Равное время — не повод отказывать НАБЛЮДЕНИЮ: потоки приходят вперемешку,
 * и «последний победил» здесь осознанное правило. Для ПОВТОРА уже разобранного
 * сырья оно неверно: повтор ничего нового не сообщает, а применённый заново
 * откатывал бы цену, состав и атрибуты, записанные другим сырьём того же
 * мгновения.
 *
 * Запретить повтор при равном времени целиком тоже нельзя: у уже разобранного
 * сырья отметка оси РАВНА его собственному `fetchedAt`, и `forceReplay`
 * перестал бы быть способом исправить заказ после починки маппера — статус
 * пересчитывался бы, а цена и состав нет.
 *
 * Различает эти два случая владелец оси: своё сырьё при равном времени
 * повторить можно, чужое — нет.
 */
final class Version20260902210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Records which raw record wrote the last snapshot and the last partial observation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_orders ADD snapshot_raw_record_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE ingest_orders ADD partial_raw_record_id UUID DEFAULT NULL');

        // Обратное заполнение ПРИБЛИЖЁННОЕ, и это сказано вслух.
        //
        // Точного ответа в данных нет: до этой миграции никто не записывал,
        // какое сырьё двигало какую ось. `last_raw_record_id` — указатель на
        // последнее наблюдение вообще; для заказов, чьим последним наблюдением
        // был полный снимок, он и есть владелец снимка. Где это не так, повтор
        // при равном времени будет отклонён — то есть поведение останется
        // прежним, осторожным. Ошибиться в эту сторону безопасно.
        $this->addSql(
            'UPDATE ingest_orders
                SET snapshot_raw_record_id = last_raw_record_id
              WHERE snapshot_observed_at IS NOT NULL
                AND last_raw_record_id IS NOT NULL'
        );

        $this->addSql(
            'UPDATE ingest_orders
                SET partial_raw_record_id = last_raw_record_id
              WHERE partial_observed_at IS NOT NULL
                AND last_raw_record_id IS NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_orders DROP partial_raw_record_id');
        $this->addSql('ALTER TABLE ingest_orders DROP snapshot_raw_record_id');
    }
}
