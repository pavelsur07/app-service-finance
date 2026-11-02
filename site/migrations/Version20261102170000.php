<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Billing, этап 7 (docs/tasks/billing): журнал оплат и попыток онлайн-оплаты не удаляется вместе
 * с компанией — FK на companies с ON DELETE RESTRICT вместо CASCADE. Данные не меняются.
 */
final class Version20261102170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Billing: keep payment journal on company delete (RESTRICT)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_payments DROP CONSTRAINT fk_billing_payments_company');
        $this->addSql('ALTER TABLE billing_payments ADD CONSTRAINT fk_billing_payments_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE billing_checkouts DROP CONSTRAINT fk_billing_checkouts_company');
        $this->addSql('ALTER TABLE billing_checkouts ADD CONSTRAINT fk_billing_checkouts_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_checkouts DROP CONSTRAINT fk_billing_checkouts_company');
        $this->addSql('ALTER TABLE billing_checkouts ADD CONSTRAINT fk_billing_checkouts_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE billing_payments DROP CONSTRAINT fk_billing_payments_company');
        $this->addSql('ALTER TABLE billing_payments ADD CONSTRAINT fk_billing_payments_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
    }
}
