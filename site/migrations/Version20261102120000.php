<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Billing, этап 5 (docs/tasks/billing): попытки онлайн-оплаты через ЮKassa. Только новая таблица.
 */
final class Version20261102120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Billing: online checkouts (YooKassa)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE billing_checkouts (id UUID NOT NULL, company_id UUID NOT NULL, tariff_id UUID NOT NULL, months INT NOT NULL, amount_kopecks INT NOT NULL, status VARCHAR(32) NOT NULL, provider_payment_id VARCHAR(64) DEFAULT NULL, confirmation_url VARCHAR(2048) DEFAULT NULL, failure_reason VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_billing_checkouts_provider_payment ON billing_checkouts (provider_payment_id)');
        $this->addSql('CREATE INDEX idx_billing_checkouts_company_created ON billing_checkouts (company_id, created_at)');
        $this->addSql('CREATE INDEX idx_billing_checkouts_status_created ON billing_checkouts (status, created_at)');
        $this->addSql('CREATE INDEX idx_billing_checkouts_tariff ON billing_checkouts (tariff_id)');
        $this->addSql('ALTER TABLE billing_checkouts ADD CONSTRAINT fk_billing_checkouts_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE billing_checkouts ADD CONSTRAINT fk_billing_checkouts_tariff FOREIGN KEY (tariff_id) REFERENCES billing_tariffs (id)');
        $this->addSql("ALTER TABLE billing_checkouts ADD CONSTRAINT chk_billing_checkouts_values CHECK (months BETWEEN 1 AND 36 AND amount_kopecks > 0 AND status IN ('pending', 'succeeded', 'canceled', 'failed_to_apply'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing_checkouts');
    }
}
