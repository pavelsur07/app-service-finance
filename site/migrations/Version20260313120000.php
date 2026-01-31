<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260313120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create billing integration table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('billing_integration')) {
            return;
        }

        $this->addSql('CREATE TABLE billing_integration (id UUID NOT NULL, code VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, billing_type VARCHAR(16) NOT NULL, price_amount INT DEFAULT NULL, price_currency VARCHAR(3) DEFAULT NULL, is_active BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_billing_integration_code ON billing_integration (code)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_billing_integration_is_active ON billing_integration (is_active)');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('billing_integration')) {
            return;
        }

        $this->addSql('DROP TABLE billing_integration');
    }
}
