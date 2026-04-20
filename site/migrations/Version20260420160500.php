<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data migration: очистка висячих `marketplace_costs.document_id`, которые
 * ссылаются на уже удалённые строки `documents`.
 *
 * Источник мусора — старый код `CloseMonthStageAction::__invoke`, который при
 * падении контрольной суммы делал «ручной rollback» через
 * `FinanceFacade::deletePLDocument`, но не откатывал сырой UPDATE
 * `marketplace_costs.document_id`, выполненный ранее. DELETE и UPDATE
 * коммитились разными транзакциями, и после падения в `marketplace_costs`
 * оставались строки со ссылками на уже удалённые документы. Это ломало
 * preflight (`document_id IS NULL` — необходимое условие для повторного
 * закрытия месяца).
 *
 * Начиная с PR этой задачи handler обёрнут в `EntityManager::wrapInTransaction`
 * → новый мусор появляться не будет. Миграция чистит только исторический
 * мусор, оставшийся от инцидентов до фикса.
 *
 * См. Sentry issue по неудачным закрытиям месяца + PR с `wrapInTransaction`.
 */
final class Version20260420160500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marketplace: NULL для висячих marketplace_costs.document_id, ссылающихся на удалённые documents';
    }

    public function up(Schema $schema): void
    {
        $orphanCount = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM marketplace_costs c
            WHERE c.document_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM documents d WHERE d.id = c.document_id
              )
        SQL);

        $this->write(sprintf(
            '    <comment>marketplace_costs orphan document_id rows to NULL: %d</comment>',
            $orphanCount,
        ));

        if ($orphanCount === 0) {
            return;
        }

        $this->addSql(<<<'SQL'
            UPDATE marketplace_costs c
            SET document_id = NULL,
                updated_at = NOW()
            WHERE c.document_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM documents d WHERE d.id = c.document_id
              )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Интенционально пусто: восстановить ссылки на уже удалённые документы
        // невозможно, бизнес-данных миграция не теряет (сами строки затрат
        // остаются на месте, обнуляется только «висячий» указатель).
    }
}
