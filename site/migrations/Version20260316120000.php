<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260316120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create billing subscription integration table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('billing_subscription_integration')) {
            return;
        }

        $this->addSql('CREATE TABLE billing_subscription_integration (id UUID NOT NULL, subscription_id UUID NOT NULL, integration_id UUID NOT NULL, status VARCHAR(16) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_billing_subscription_integration_subscription_integration ON billing_subscription_integration (subscription_id, integration_id)');

        if ($schema->hasTable('billing_subscription')) {
            $this->addSql('ALTER TABLE billing_subscription_integration ADD CONSTRAINT fk_billing_subscription_integration_subscription FOREIGN KEY (subscription_id) REFERENCES billing_subscription (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        }

        if ($schema->hasTable('billing_integration')) {
            $this->addSql('ALTER TABLE billing_subscription_integration ADD CONSTRAINT fk_billing_subscription_integration_integration FOREIGN KEY (integration_id) REFERENCES billing_integration (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('billing_subscription_integration')) {
            return;
        }

        $this->addSql('DROP TABLE billing_subscription_integration');
    }
}
