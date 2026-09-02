<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retention удаляет ПОЛЕЗНУЮ НАГРУЗКУ, а строку сырья оставляет.
 *
 * Прежняя модель — удалять объект вместе со строкой — оказалась источником
 * целого класса гонок, и каждая закрывалась правкой очередной подсистемы:
 * запись могла исчезнуть между проверкой и созданием `NormalizationIssue`, а
 * дедуп при часовом опросе обновлял `last_seen_at` у строки, которую retention
 * уже удалил, и свежая выгрузка терялась молча.
 *
 * Дорого стоит объект в хранилище, а не строка метаданных в сотню байт.
 * Поэтому удаляется объект, а строка получает отметку `payload_pruned_at` и
 * живёт дальше:
 *
 * - висячих указателей нет: `ingest_financial_transactions`,
 *   `ingest_order_status_events` и `ingest_orders.last_raw_record_id`
 *   по-прежнему разрешаются, а чтение сырья отвечает внятной ошибкой вместо
 *   сбоя хранилища;
 * - дедупу нечего терять: строка на месте, `markSeen()` обновляет
 *   существующую запись;
 * - `StoreRawBatchAction::repairMissingObject()` уже умеет вернуть объект,
 *   если та же выгрузка приедет снова, — отметка при этом снимается, и модель
 *   самовосстанавливается;
 * - проблема, заведённая на такое сырьё, видит запись и знает, что нагрузка
 *   удалена и когда. Это деградация, но видимая, а не тихая потеря.
 */
final class Version20260902180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds a payload retention mark to raw records instead of deleting the rows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_raw_records ADD payload_pruned_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN ingest_raw_records.payload_pruned_at IS '(DC2Type:datetime_immutable_us)'");

        $this->addSql(
            'CREATE INDEX idx_ingest_raw_record_retention
             ON ingest_raw_records (last_seen_at)
             WHERE payload_pruned_at IS NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ingest_raw_record_retention');
        $this->addSql('ALTER TABLE ingest_raw_records DROP payload_pruned_at');
    }
}
