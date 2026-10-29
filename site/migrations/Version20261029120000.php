<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Один документ ОПиУ — один контрагент: контрагент операции приводится к контрагенту шапки.
 *
 * - Шапка задана — она и есть контрагент документа, операции получают её значение.
 * - Шапка пуста, у операций ровно один контрагент компании документа — он поднимается в шапку
 *   (иначе первое сохранение документа молча стёрло бы его у операций).
 * - Шапка пуста, у операций несколько разных контрагентов или чужой компании — выбрать
 *   нельзя: миграция останавливается, документы разбираются вручную.
 *
 * Прежние значения всех операций затронутых документов — в document_counterparty_repair_backup;
 * down() восстанавливает их и удаляет таблицу. Регистр ОПиУ контрагента не хранит — пересчёт
 * не нужен. Прод 29.10.2026: 5 документов, 5 операций (docs/tasks/pnl-document-single-counterparty).
 */
final class Version20261029120000 extends AbstractMigration
{
    private const MISMATCHED_DOCUMENTS = <<<'SQL'
        SELECT DISTINCT o.document_id
        FROM document_operations o
        JOIN documents d ON d.id = o.document_id
        WHERE o.counterparty_id IS DISTINCT FROM d.counterparty_id
        SQL;

    public function getDescription(): string
    {
        return 'P&L document: operation counterparty = document counterparty (repair with backup)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $ambiguous = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT count(*) FROM (
                SELECT o.document_id
                FROM document_operations o
                JOIN documents d ON d.id = o.document_id
                LEFT JOIN counterparty c ON c.id = o.counterparty_id
                WHERE d.counterparty_id IS NULL AND o.counterparty_id IS NOT NULL
                GROUP BY o.document_id
                HAVING count(DISTINCT o.counterparty_id) > 1 OR bool_or(c.company_id IS DISTINCT FROM d.company_id)
            ) t
            SQL);
        $this->abortIf(
            $ambiguous > 0,
            sprintf('%d P&L document(s) without header counterparty have several or foreign operation counterparties: resolve manually.', $ambiguous),
        );

        $this->addSql('CREATE TABLE document_counterparty_repair_backup (operation_id UUID NOT NULL, document_id UUID NOT NULL, document_counterparty_id UUID DEFAULT NULL, operation_counterparty_id UUID DEFAULT NULL, PRIMARY KEY (operation_id))');
        $this->addSql(sprintf(<<<'SQL'
            INSERT INTO document_counterparty_repair_backup (operation_id, document_id, document_counterparty_id, operation_counterparty_id)
            SELECT o.id, o.document_id, d.counterparty_id, o.counterparty_id
            FROM document_operations o
            JOIN documents d ON d.id = o.document_id
            WHERE o.document_id IN (%s)
            SQL, self::MISMATCHED_DOCUMENTS));

        $this->addSql(<<<'SQL'
            UPDATE documents d
            SET counterparty_id = x.counterparty_id
            FROM (
                SELECT o.document_id, (array_agg(DISTINCT o.counterparty_id))[1] AS counterparty_id
                FROM document_operations o
                WHERE o.counterparty_id IS NOT NULL
                GROUP BY o.document_id
            ) x
            WHERE x.document_id = d.id AND d.counterparty_id IS NULL
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE document_operations o
            SET counterparty_id = d.counterparty_id
            FROM documents d
            WHERE d.id = o.document_id AND o.counterparty_id IS DISTINCT FROM d.counterparty_id
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $this->addSql(<<<'SQL'
            UPDATE documents d
            SET counterparty_id = b.document_counterparty_id
            FROM (SELECT DISTINCT document_id, document_counterparty_id FROM document_counterparty_repair_backup) b
            WHERE b.document_id = d.id
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE document_operations o
            SET counterparty_id = b.operation_counterparty_id
            FROM document_counterparty_repair_backup b
            WHERE b.operation_id = o.id
            SQL);
        $this->addSql('DROP TABLE document_counterparty_repair_backup');
    }

    private function abortIfNotPostgres(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );
    }
}
