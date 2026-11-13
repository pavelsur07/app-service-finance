<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113171344 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Durable Cash payment intake drafts with global Telegram source binding and replay guards';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cash_payment_intake_draft (id UUID NOT NULL, company_id UUID NOT NULL, actor_user_id UUID NOT NULL, money_account_id UUID NOT NULL, source_key VARCHAR(255) NOT NULL, source_revision VARCHAR(64) NOT NULL, text TEXT NOT NULL, received_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, timezone VARCHAR(64) NOT NULL, currency VARCHAR(3) NOT NULL, revision INT NOT NULL, attempt INT NOT NULL, task_revision INT NOT NULL, status VARCHAR(24) NOT NULL, task_id UUID DEFAULT NULL, items JSONB NOT NULL, questions JSONB NOT NULL, payload JSONB NOT NULL, transaction_ids JSONB NOT NULL, confirmed_item_ids JSONB NOT NULL, confirmed_revision INT DEFAULT NULL, error_code VARCHAR(64) DEFAULT NULL, dispatch_pending BOOLEAN NOT NULL, cash_event_pending BOOLEAN NOT NULL, last_enqueued_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_payment_intake_company_status ON cash_payment_intake_draft (company_id, status)');
        $this->addSql('CREATE INDEX idx_payment_intake_task ON cash_payment_intake_draft (company_id, task_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_intake_source ON cash_payment_intake_draft (source_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cash_payment_intake_draft');
    }
}
