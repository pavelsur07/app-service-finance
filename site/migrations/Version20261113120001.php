<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bind Telegram intake to actual application actor and persist source/delivery sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_links ADD actor_user_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE client_bindings ADD actor_user_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE client_bindings ADD selected BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE client_bindings ADD payment_intake_selected BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_selected_client_binding ON client_bindings (bot_id, telegram_user_id) WHERE (selected = true)');
        $this->addSql('CREATE TABLE telegram_payment_intake_session (id UUID NOT NULL, binding_id UUID NOT NULL, bot_id UUID NOT NULL, company_id UUID NOT NULL, chat_id VARCHAR(32) NOT NULL, message_id VARCHAR(32) NOT NULL, draft_id UUID NOT NULL, source_revision VARCHAR(64) NOT NULL, delivered_state VARCHAR(100) NOT NULL, delivery_pending BOOLEAN NOT NULL, delivery_request_token UUID DEFAULT NULL, delivery_cursor INT NOT NULL, sending_state VARCHAR(100) NOT NULL, reply_message_ids JSON NOT NULL, editing_item_id UUID DEFAULT NULL, editing_revision INT DEFAULT NULL, last_sequence INT NOT NULL, pending_poll JSON DEFAULT NULL, poll_generation UUID DEFAULT NULL, poll_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, lease_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_telegram_intake_source ON telegram_payment_intake_session (bot_id, chat_id, message_id)');
        $this->addSql('CREATE INDEX idx_tg_intake_company_recovery ON telegram_payment_intake_session (company_id, id)');
        $this->addSql('CREATE INDEX IDX_81B466554AC8159D ON telegram_payment_intake_session (binding_id)');
        $this->addSql('ALTER TABLE telegram_payment_intake_session ADD CONSTRAINT FK_TELEGRAM_INTAKE_BINDING FOREIGN KEY (binding_id) REFERENCES client_bindings (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE telegram_payment_intake_session');
        $this->addSql('ALTER TABLE client_bindings DROP actor_user_id, DROP selected, DROP payment_intake_selected');
        $this->addSql('ALTER TABLE bot_links DROP actor_user_id');
    }
}
