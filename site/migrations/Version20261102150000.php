<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Billing, этап 6 (docs/tasks/billing): смена тарифа при действующем периоде — запланированный
 * переход у подписки и перенесённый остаток у оплаты. Только новые колонки со значениями по
 * умолчанию, данные не меняются.
 */
final class Version20261102150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Billing: scheduled tariff change and carried balance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_company_subscriptions ADD next_tariff_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE billing_company_subscriptions ADD next_tariff_from DATE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_billing_company_subscriptions_next_tariff ON billing_company_subscriptions (next_tariff_id)');
        $this->addSql('ALTER TABLE billing_company_subscriptions ADD CONSTRAINT fk_billing_company_subscriptions_next_tariff FOREIGN KEY (next_tariff_id) REFERENCES billing_tariffs (id)');
        $this->addSql('ALTER TABLE billing_company_subscriptions ADD CONSTRAINT chk_billing_company_subscriptions_next CHECK ((next_tariff_id IS NULL) = (next_tariff_from IS NULL) AND (next_tariff_from IS NULL OR (paid_until IS NOT NULL AND next_tariff_from > paid_from AND next_tariff_from <= paid_until)))');
        $this->addSql('ALTER TABLE billing_payments ADD carried_days INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE billing_payments ADD carried_kopecks BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE billing_payments ADD CONSTRAINT chk_billing_payments_carried CHECK (carried_days >= 0 AND carried_kopecks >= 0)');
        $this->addSql('ALTER TABLE billing_payments ADD superseded_by_payment_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_billing_payments_superseded_by ON billing_payments (superseded_by_payment_id)');
        $this->addSql('ALTER TABLE billing_payments ADD CONSTRAINT fk_billing_payments_superseded_by FOREIGN KEY (superseded_by_payment_id) REFERENCES billing_payments (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE billing_payments ADD CONSTRAINT chk_billing_payments_superseded CHECK (superseded_by_payment_id IS NULL OR superseded_by_payment_id <> id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_payments DROP CONSTRAINT chk_billing_payments_superseded');
        $this->addSql('ALTER TABLE billing_payments DROP CONSTRAINT fk_billing_payments_superseded_by');
        $this->addSql('DROP INDEX idx_billing_payments_superseded_by');
        $this->addSql('ALTER TABLE billing_payments DROP superseded_by_payment_id');
        $this->addSql('ALTER TABLE billing_payments DROP CONSTRAINT chk_billing_payments_carried');
        $this->addSql('ALTER TABLE billing_payments DROP carried_kopecks');
        $this->addSql('ALTER TABLE billing_payments DROP carried_days');
        $this->addSql('ALTER TABLE billing_company_subscriptions DROP CONSTRAINT chk_billing_company_subscriptions_next');
        $this->addSql('ALTER TABLE billing_company_subscriptions DROP CONSTRAINT fk_billing_company_subscriptions_next_tariff');
        $this->addSql('DROP INDEX idx_billing_company_subscriptions_next_tariff');
        $this->addSql('ALTER TABLE billing_company_subscriptions DROP next_tariff_from');
        $this->addSql('ALTER TABLE billing_company_subscriptions DROP next_tariff_id');
    }
}
