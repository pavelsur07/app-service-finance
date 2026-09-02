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
 * Поэтому удаляется объект, а строка живёт дальше:
 *
 * - висячих указателей нет: `ingest_financial_transactions`,
 *   `ingest_order_status_events` и `ingest_orders.last_raw_record_id`
 *   по-прежнему разрешаются, а чтение сырья отвечает внятной ошибкой вместо
 *   сбоя хранилища;
 * - дедупу нечего терять: строка на месте, `markSeen()` обновляет
 *   существующую запись;
 * - `StoreRawBatchAction` вернёт объект, если та же выгрузка приедет снова, —
 *   отметки при этом снимаются, и модель самовосстанавливается.
 *
 * ДВЕ отметки, а не одна, потому что объектное хранилище не транзакционно:
 *
 * - `payload_pruned_at` — РЕШЕНИЕ удалить, коммитится ДО обращения к
 *   хранилищу. Читатели с этого момента считают нагрузку недоступной;
 * - `payload_deleted_at` — объект действительно удалён, ставится после
 *   успешного `delete()`.
 *
 * Одной отметки не хватает: она проставляется в памяти, а коммит происходит
 * после удаления объекта, и падение между ними откатывало бы решение при уже
 * уничтоженных данных — «строка утверждает, что нагрузка есть, объекта нет».
 * Две отметки делают незавершённое состояние видимым и повторяемым: следующий
 * прогон найдёт помеченные, но не удалённые, и доведёт дело до конца.
 */
final class Version20260902180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds payload retention marks to raw records instead of deleting the rows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_raw_records ADD payload_pruned_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN ingest_raw_records.payload_pruned_at IS '(DC2Type:datetime_immutable_us)'");

        $this->addSql('ALTER TABLE ingest_raw_records ADD payload_deleted_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN ingest_raw_records.payload_deleted_at IS '(DC2Type:datetime_immutable_us)'");
    }

    public function down(Schema $schema): void
    {
        // Проверка ДО DDL. После первого же `--execute` отметка — единственный
        // признак того, что нагрузка удалена намеренно; объекты при этом уже
        // невосстановимы. Сняв колонку, мы вернули бы строкам вид записей с
        // нагрузкой, и чтение падало бы ошибкой хранилища.
        $pruned = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ingest_raw_records WHERE payload_pruned_at IS NOT NULL'
        );

        $this->abortIf(
            $pruned > 0,
            sprintf(
                'Rollback would erase the only evidence that %d payload(s) were pruned on purpose. Decide what to do with them first.',
                $pruned,
            ),
        );

        $this->addSql('ALTER TABLE ingest_raw_records DROP payload_deleted_at');
        $this->addSql('ALTER TABLE ingest_raw_records DROP payload_pruned_at');
    }
}
