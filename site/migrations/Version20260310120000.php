<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260310120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create billing plan table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('billing_plan')) {
            return;
        }

        $this->addSql('CREATE TABLE billing_plan (id UUID NOT NULL, code VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, price_amount INT NOT NULL, price_currency VARCHAR(3) NOT NULL, billing_period VARCHAR(16) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_billing_plan_code ON billing_plan (code)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_billing_plan_is_active ON billing_plan (is_active)');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('billing_plan')) {
            return;
        }

        $this->addSql('DROP TABLE billing_plan');
    }
}
