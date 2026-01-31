<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create billing subscription table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('billing_subscription')) {
            return;
        }

        $this->addSql('CREATE TABLE billing_subscription (id UUID NOT NULL, company_id UUID NOT NULL, plan_id UUID NOT NULL, status VARCHAR(16) NOT NULL, trial_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, current_period_start TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, current_period_end TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, cancel_at_period_end BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_billing_subscription_company ON billing_subscription (company_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_billing_subscription_status ON billing_subscription (status)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_billing_subscription_current_period_end ON billing_subscription (current_period_end)');
        $this->addSql('ALTER TABLE billing_subscription ADD CONSTRAINT fk_billing_subscription_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE billing_subscription ADD CONSTRAINT fk_billing_subscription_plan FOREIGN KEY (plan_id) REFERENCES billing_plan (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('billing_subscription')) {
            return;
        }

        $this->addSql('DROP TABLE billing_subscription');
    }
}
