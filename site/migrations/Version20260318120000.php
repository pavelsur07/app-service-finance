<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create billing usage counter table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('billing_usage_counter')) {
            return;
        }

        $this->addSql('CREATE TABLE billing_usage_counter (id UUID NOT NULL, company_id UUID NOT NULL, period_key VARCHAR(7) NOT NULL, metric VARCHAR(255) NOT NULL, used BIGINT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_billing_usage_counter_company_period_metric ON billing_usage_counter (company_id, period_key, metric)');

        if ($schema->hasTable('company')) {
            $this->addSql(
                <<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_billing_usage_counter_company'
    ) THEN
        ALTER TABLE billing_usage_counter
            ADD CONSTRAINT fk_billing_usage_counter_company
            FOREIGN KEY (company_id) REFERENCES company (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE;
    END IF;
END
$$;
SQL
            );
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('billing_usage_counter')) {
            return;
        }

        $this->addSql('DROP TABLE billing_usage_counter');
    }
}
