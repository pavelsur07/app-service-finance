<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260311120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create billing feature table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('billing_feature')) {
            return;
        }

        $this->addSql('CREATE TABLE billing_feature (id UUID NOT NULL, code VARCHAR(255) NOT NULL, type VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_billing_feature_code ON billing_feature (code)');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('billing_feature')) {
            return;
        }

        $this->addSql('DROP TABLE billing_feature');
    }
}
