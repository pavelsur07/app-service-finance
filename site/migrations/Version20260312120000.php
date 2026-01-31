<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260312120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create billing plan feature table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('billing_plan_feature')) {
            return;
        }

        $this->addSql('CREATE TABLE billing_plan_feature (id UUID NOT NULL, plan_id UUID NOT NULL, feature_id UUID NOT NULL, value VARCHAR(255) NOT NULL, soft_limit INT DEFAULT NULL, hard_limit INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_billing_plan_feature_plan_feature ON billing_plan_feature (plan_id, feature_id)');
        $this->addSql('ALTER TABLE billing_plan_feature ADD CONSTRAINT fk_billing_plan_feature_plan FOREIGN KEY (plan_id) REFERENCES billing_plan (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE billing_plan_feature ADD CONSTRAINT fk_billing_plan_feature_feature FOREIGN KEY (feature_id) REFERENCES billing_feature (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('billing_plan_feature')) {
            return;
        }

        $this->addSql('DROP TABLE billing_plan_feature');
    }
}
