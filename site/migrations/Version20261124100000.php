<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Удаление ИИ-разбора платежей (2.244, решение Владельца 23.11): Telegram-черновики и сессии
 * удаляются (бэкап делает release), остаются только MCP-черновики. Необратима.
 */
final class Version20261124100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop payment intake AI tables, columns and Telegram drafts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE telegram_payment_intake_session');
        $this->addSql('DROP TABLE cash_payment_intake_reply_receipt');
        $this->addSql('DROP TABLE ai_quality_monitor');
        $this->addSql('DROP TABLE ai_quality_run');
        $this->addSql('DELETE FROM cash_payment_intake_draft WHERE mcp_connection_id IS NULL');
        $this->addSql('DROP INDEX idx_payment_intake_task');
        $this->addSql('ALTER TABLE cash_payment_intake_draft DROP task_id, DROP attempt, DROP task_revision, DROP dispatch_pending, DROP last_enqueued_at, DROP error_code, DROP payload, DROP questions');
        $this->addSql('ALTER TABLE client_bindings DROP payment_intake_selected');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Удалённые данные ИИ-разбора восстанавливаются только из бэкапа.');
    }
}
