<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Billing, этап 3 (docs/tasks/billing): признак пробного периода подписки и журнал
 * отправленных напоминаний об окончании оплаты. Данные не меняются: существующие подписки
 * получают is_trial = false.
 */
final class Version20261102100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Billing: trial flag on subscriptions, expiry reminders journal';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_company_subscriptions ADD is_trial BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE billing_company_subscriptions ADD CONSTRAINT chk_billing_company_subscriptions_trial CHECK (NOT is_trial OR paid_until IS NOT NULL)');

        $this->addSql('CREATE TABLE billing_expiry_reminders (id UUID NOT NULL, company_id UUID NOT NULL, paid_until DATE NOT NULL, days_before SMALLINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_expiry_reminders_company_until_days ON billing_expiry_reminders (company_id, paid_until, days_before)');
        $this->addSql('ALTER TABLE billing_expiry_reminders ADD CONSTRAINT fk_billing_expiry_reminders_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE billing_expiry_reminders ADD CONSTRAINT chk_billing_expiry_reminders_days CHECK (days_before >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing_expiry_reminders');
        $this->addSql('ALTER TABLE billing_company_subscriptions DROP CONSTRAINT chk_billing_company_subscriptions_trial');
        $this->addSql('ALTER TABLE billing_company_subscriptions DROP is_trial');
    }
}
