<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Удаляет таблицы старого модуля Billing (docs/tasks/billing, этап 0).
 * На проде 01.11.2026 все 7 таблиц пусты; down() воссоздаёт пустую схему.
 */
final class Version20261101200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop legacy billing_* tables (unused, empty)';
    }

    public function up(Schema $schema): void
    {
        // Порядок: зависимые раньше тех, на кого ссылаются.
        $this->addSql('DROP TABLE IF EXISTS billing_subscription_integration');
        $this->addSql('DROP TABLE IF EXISTS billing_subscription');
        $this->addSql('DROP TABLE IF EXISTS billing_plan_feature');
        $this->addSql('DROP TABLE IF EXISTS billing_usage_counter');
        $this->addSql('DROP TABLE IF EXISTS billing_integration');
        $this->addSql('DROP TABLE IF EXISTS billing_feature');
        $this->addSql('DROP TABLE IF EXISTS billing_plan');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE billing_plan (id UUID NOT NULL, code VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, price_amount INT NOT NULL, price_currency VARCHAR(3) NOT NULL, billing_period VARCHAR(16) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_plan_code ON billing_plan (code)');
        $this->addSql('CREATE INDEX idx_billing_plan_is_active ON billing_plan (is_active)');

        $this->addSql('CREATE TABLE billing_feature (id UUID NOT NULL, code VARCHAR(255) NOT NULL, type VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_feature_code ON billing_feature (code)');

        $this->addSql('CREATE TABLE billing_integration (id UUID NOT NULL, code VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, billing_type VARCHAR(16) NOT NULL, price_amount INT DEFAULT NULL, price_currency VARCHAR(3) DEFAULT NULL, is_active BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_integration_code ON billing_integration (code)');
        $this->addSql('CREATE INDEX idx_billing_integration_is_active ON billing_integration (is_active)');

        $this->addSql('CREATE TABLE billing_usage_counter (id UUID NOT NULL, company_id UUID NOT NULL, period_key VARCHAR(7) NOT NULL, metric VARCHAR(255) NOT NULL, used INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_usage_counter_company_period_metric ON billing_usage_counter (company_id, period_key, metric)');

        $this->addSql('CREATE TABLE billing_plan_feature (id UUID NOT NULL, plan_id UUID NOT NULL, feature_id UUID NOT NULL, value VARCHAR(255) NOT NULL, soft_limit INT DEFAULT NULL, hard_limit INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_plan_feature_plan_feature ON billing_plan_feature (plan_id, feature_id)');
        $this->addSql('ALTER TABLE billing_plan_feature ADD CONSTRAINT fk_billing_plan_feature_plan FOREIGN KEY (plan_id) REFERENCES billing_plan (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE billing_plan_feature ADD CONSTRAINT fk_billing_plan_feature_feature FOREIGN KEY (feature_id) REFERENCES billing_feature (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE billing_subscription (id UUID NOT NULL, company_id UUID NOT NULL, plan_id UUID NOT NULL, status VARCHAR(16) NOT NULL, trial_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, current_period_start TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, current_period_end TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, cancel_at_period_end BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_billing_subscription_company_status_period_end ON billing_subscription (company_id, status, current_period_end)');
        $this->addSql('ALTER TABLE billing_subscription ADD CONSTRAINT fk_billing_subscription_plan FOREIGN KEY (plan_id) REFERENCES billing_plan (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE billing_subscription_integration (id UUID NOT NULL, subscription_id UUID NOT NULL, integration_id UUID NOT NULL, status VARCHAR(16) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_subscription_integration_subscription_integration ON billing_subscription_integration (subscription_id, integration_id)');
        $this->addSql('ALTER TABLE billing_subscription_integration ADD CONSTRAINT fk_billing_subscription_integration_subscription FOREIGN KEY (subscription_id) REFERENCES billing_subscription (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE billing_subscription_integration ADD CONSTRAINT fk_billing_subscription_integration_integration FOREIGN KEY (integration_id) REFERENCES billing_integration (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
