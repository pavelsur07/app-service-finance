<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Новый модуль Billing (docs/tasks/billing, этап 1): тарифы, цены за срок, подписка компании,
 * журнал оплат; справочник 6 тарифов и 12 цен публичных тарифов.
 */
final class Version20261101210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Billing: tariffs, tariff prices, company subscriptions, payments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE billing_tariffs (id UUID NOT NULL, code VARCHAR(32) NOT NULL, name VARCHAR(64) NOT NULL, is_public BOOLEAN NOT NULL, is_perpetual BOOLEAN NOT NULL, is_active BOOLEAN NOT NULL, sort_order INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_tariffs_code ON billing_tariffs (code)');

        $this->addSql('CREATE TABLE billing_tariff_prices (id UUID NOT NULL, tariff_id UUID NOT NULL, months INT NOT NULL, amount_kopecks INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_tariff_prices_tariff_months ON billing_tariff_prices (tariff_id, months)');
        $this->addSql('ALTER TABLE billing_tariff_prices ADD CONSTRAINT fk_billing_tariff_prices_tariff FOREIGN KEY (tariff_id) REFERENCES billing_tariffs (id)');
        $this->addSql('ALTER TABLE billing_tariff_prices ADD CONSTRAINT chk_billing_tariff_prices_months CHECK (months IN (1, 3, 6, 12))');
        $this->addSql('ALTER TABLE billing_tariff_prices ADD CONSTRAINT chk_billing_tariff_prices_amount CHECK (amount_kopecks > 0)');

        $this->addSql('CREATE TABLE billing_company_subscriptions (id UUID NOT NULL, company_id UUID NOT NULL, tariff_id UUID NOT NULL, paid_from DATE NOT NULL, paid_until DATE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_company_subscriptions_company ON billing_company_subscriptions (company_id)');
        $this->addSql('CREATE INDEX idx_billing_company_subscriptions_tariff ON billing_company_subscriptions (tariff_id)');
        $this->addSql('ALTER TABLE billing_company_subscriptions ADD CONSTRAINT fk_billing_company_subscriptions_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE billing_company_subscriptions ADD CONSTRAINT fk_billing_company_subscriptions_tariff FOREIGN KEY (tariff_id) REFERENCES billing_tariffs (id)');
        $this->addSql('ALTER TABLE billing_company_subscriptions ADD CONSTRAINT chk_billing_company_subscriptions_period CHECK (paid_until IS NULL OR paid_from <= paid_until)');

        $this->addSql('CREATE TABLE billing_payments (id UUID NOT NULL, company_id UUID NOT NULL, tariff_id UUID NOT NULL, months INT NOT NULL, amount_kopecks INT NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, period_from DATE NOT NULL, period_until DATE NOT NULL, source VARCHAR(32) NOT NULL, external_id VARCHAR(128) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_payments_source_external ON billing_payments (source, external_id)');
        $this->addSql('CREATE INDEX idx_billing_payments_company_received ON billing_payments (company_id, received_at)');
        $this->addSql('CREATE INDEX idx_billing_payments_tariff ON billing_payments (tariff_id)');
        $this->addSql('ALTER TABLE billing_payments ADD CONSTRAINT fk_billing_payments_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE billing_payments ADD CONSTRAINT fk_billing_payments_tariff FOREIGN KEY (tariff_id) REFERENCES billing_tariffs (id)');
        $this->addSql('ALTER TABLE billing_payments ADD CONSTRAINT chk_billing_payments_values CHECK (months >= 1 AND amount_kopecks >= 0 AND period_from <= period_until)');

        $this->addSql(<<<'SQL'
            INSERT INTO billing_tariffs (id, code, name, is_public, is_perpetual, is_active, sort_order, created_at) VALUES
            ('01a18a1b-3000-7000-8000-000000000001', 'start', 'Старт', TRUE, FALSE, TRUE, 10, NOW()),
            ('01a18a1b-3000-7000-8000-000000000002', 'base', 'База', TRUE, FALSE, TRUE, 20, NOW()),
            ('01a18a1b-3000-7000-8000-000000000003', 'pro', 'Профи', TRUE, FALSE, TRUE, 30, NOW()),
            ('01a18a1b-3000-7000-8000-000000000004', 'friend', 'Друг', FALSE, TRUE, TRUE, 100, NOW()),
            ('01a18a1b-3000-7000-8000-000000000005', 'support', 'На сопровождении', FALSE, FALSE, TRUE, 110, NOW()),
            ('01a18a1b-3000-7000-8000-000000000006', 'outsourcer', 'Аутсорсер', FALSE, FALSE, TRUE, 120, NOW())
            SQL);

        // Скидки 5% / 10% / 20% за 3 / 6 / 12 месяцев, округление до 10 ₽ (решение №1 TASK.md).
        $this->addSql(<<<'SQL'
            INSERT INTO billing_tariff_prices (id, tariff_id, months, amount_kopecks) VALUES
            ('01a18a1b-3000-7000-8000-000000000121', '01a18a1b-3000-7000-8000-000000000001', 1, 497000),
            ('01a18a1b-3000-7000-8000-000000000123', '01a18a1b-3000-7000-8000-000000000001', 3, 1416000),
            ('01a18a1b-3000-7000-8000-000000000126', '01a18a1b-3000-7000-8000-000000000001', 6, 2684000),
            ('01a18a1b-3000-7000-8000-00000000012c', '01a18a1b-3000-7000-8000-000000000001', 12, 4771000),
            ('01a18a1b-3000-7000-8000-000000000131', '01a18a1b-3000-7000-8000-000000000002', 1, 995000),
            ('01a18a1b-3000-7000-8000-000000000133', '01a18a1b-3000-7000-8000-000000000002', 3, 2836000),
            ('01a18a1b-3000-7000-8000-000000000136', '01a18a1b-3000-7000-8000-000000000002', 6, 5373000),
            ('01a18a1b-3000-7000-8000-00000000013c', '01a18a1b-3000-7000-8000-000000000002', 12, 9552000),
            ('01a18a1b-3000-7000-8000-000000000141', '01a18a1b-3000-7000-8000-000000000003', 1, 1479000),
            ('01a18a1b-3000-7000-8000-000000000143', '01a18a1b-3000-7000-8000-000000000003', 3, 4215000),
            ('01a18a1b-3000-7000-8000-000000000146', '01a18a1b-3000-7000-8000-000000000003', 6, 7987000),
            ('01a18a1b-3000-7000-8000-00000000014c', '01a18a1b-3000-7000-8000-000000000003', 12, 14198000)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing_payments');
        $this->addSql('DROP TABLE billing_company_subscriptions');
        $this->addSql('DROP TABLE billing_tariff_prices');
        $this->addSql('DROP TABLE billing_tariffs');
    }
}
