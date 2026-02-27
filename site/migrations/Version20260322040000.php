<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split payment_plan planned_at into expected_at/document_date and add probability/source/external_id metadata';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('payment_plan')) {
            return;
        }

        $table = $schema->getTable('payment_plan');
        $indexes = $table->getIndexes();

        if ($table->hasColumn('planned_at') && !$table->hasColumn('expected_at')) {
            $this->addSql('ALTER TABLE payment_plan RENAME COLUMN planned_at TO expected_at');
        }

        if (!$table->hasColumn('document_date')) {
            $this->addSql('ALTER TABLE payment_plan ADD document_date DATE DEFAULT NULL');
            $this->addSql('UPDATE payment_plan SET document_date = expected_at WHERE document_date IS NULL');
            $this->addSql('ALTER TABLE payment_plan ALTER COLUMN document_date SET NOT NULL');
        }

        if (!$table->hasColumn('probability')) {
            $this->addSql('ALTER TABLE payment_plan ADD probability SMALLINT DEFAULT 100 NOT NULL');
        }

        if (!$table->hasColumn('source')) {
            $this->addSql("ALTER TABLE payment_plan ADD source VARCHAR(255) DEFAULT 'MANUAL' NOT NULL");
        }

        if (!$table->hasColumn('external_id')) {
            $this->addSql('ALTER TABLE payment_plan ADD external_id VARCHAR(255) DEFAULT NULL');
        }

        $this->addSql('ALTER TABLE payment_plan DROP CONSTRAINT IF EXISTS chk_payment_plan_probability_range');
        $this->addSql('ALTER TABLE payment_plan ADD CONSTRAINT chk_payment_plan_probability_range CHECK (probability BETWEEN 0 AND 100)');

        if (isset($indexes['idx_payment_plan_company_planned_at'])) {
            $this->addSql('DROP INDEX idx_payment_plan_company_planned_at');
        }

        if (!isset($indexes['idx_payment_plan_company_expected_at'])) {
            $this->addSql('CREATE INDEX idx_payment_plan_company_expected_at ON payment_plan (company_id, expected_at)');
        }

        if (!isset($indexes['idx_payment_plan_external_sync'])) {
            $this->addSql('CREATE INDEX idx_payment_plan_external_sync ON payment_plan (company_id, source, external_id)');
        }

        $this->addSql("COMMENT ON COLUMN payment_plan.expected_at IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN payment_plan.document_date IS '(DC2Type:date_immutable)'");
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('payment_plan')) {
            return;
        }

        $table = $schema->getTable('payment_plan');
        $indexes = $table->getIndexes();

        if (isset($indexes['idx_payment_plan_external_sync'])) {
            $this->addSql('DROP INDEX idx_payment_plan_external_sync');
        }

        if (isset($indexes['idx_payment_plan_company_expected_at'])) {
            $this->addSql('DROP INDEX idx_payment_plan_company_expected_at');
        }

        $this->addSql('ALTER TABLE payment_plan DROP CONSTRAINT IF EXISTS chk_payment_plan_probability_range');

        if ($table->hasColumn('external_id')) {
            $this->addSql('ALTER TABLE payment_plan DROP external_id');
        }

        if ($table->hasColumn('source')) {
            $this->addSql('ALTER TABLE payment_plan DROP source');
        }

        if ($table->hasColumn('probability')) {
            $this->addSql('ALTER TABLE payment_plan DROP probability');
        }

        if ($table->hasColumn('document_date')) {
            $this->addSql('ALTER TABLE payment_plan DROP document_date');
        }

        if ($table->hasColumn('expected_at') && !$table->hasColumn('planned_at')) {
            $this->addSql('ALTER TABLE payment_plan RENAME COLUMN expected_at TO planned_at');
        }

        if (!isset($indexes['idx_payment_plan_company_planned_at'])) {
            $this->addSql('CREATE INDEX idx_payment_plan_company_planned_at ON payment_plan (company_id, planned_at)');
        }

        $this->addSql("COMMENT ON COLUMN payment_plan.planned_at IS '(DC2Type:date_immutable)'");
    }
}
