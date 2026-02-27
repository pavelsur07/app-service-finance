<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add predictive metadata to counterparty and payment_plan';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('counterparty')) {
            $counterparty = $schema->getTable('counterparty');

            if (!$counterparty->hasColumn('average_delay_days')) {
                $this->addSql('ALTER TABLE counterparty ADD average_delay_days INT DEFAULT NULL');
            }

            if (!$counterparty->hasColumn('reliability_score')) {
                $this->addSql('ALTER TABLE counterparty ADD reliability_score INT DEFAULT 100 NOT NULL');
            }

            if (!$counterparty->hasColumn('last_scored_at')) {
                $this->addSql('ALTER TABLE counterparty ADD last_scored_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
                $this->addSql("COMMENT ON COLUMN counterparty.last_scored_at IS '(DC2Type:datetime_immutable)'");
            }
        }

        if ($schema->hasTable('payment_plan')) {
            $paymentPlan = $schema->getTable('payment_plan');

            if (!$paymentPlan->hasColumn('is_frozen')) {
                $this->addSql('ALTER TABLE payment_plan ADD is_frozen BOOLEAN DEFAULT FALSE NOT NULL');
            }

            if ($paymentPlan->hasColumn('expected_at') && $paymentPlan->getColumn('expected_at')->getNotnull()) {
                $this->addSql('ALTER TABLE payment_plan ALTER COLUMN expected_at DROP NOT NULL');
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('payment_plan')) {
            $paymentPlan = $schema->getTable('payment_plan');

            if ($paymentPlan->hasColumn('expected_at') && !$paymentPlan->getColumn('expected_at')->getNotnull()) {
                $this->addSql("UPDATE payment_plan SET expected_at = document_date WHERE expected_at IS NULL");
                $this->addSql('ALTER TABLE payment_plan ALTER COLUMN expected_at SET NOT NULL');
            }

            if ($paymentPlan->hasColumn('is_frozen')) {
                $this->addSql('ALTER TABLE payment_plan DROP COLUMN is_frozen');
            }
        }

        if ($schema->hasTable('counterparty')) {
            $counterparty = $schema->getTable('counterparty');

            if ($counterparty->hasColumn('last_scored_at')) {
                $this->addSql('ALTER TABLE counterparty DROP COLUMN last_scored_at');
            }

            if ($counterparty->hasColumn('reliability_score')) {
                $this->addSql('ALTER TABLE counterparty DROP COLUMN reliability_score');
            }

            if ($counterparty->hasColumn('average_delay_days')) {
                $this->addSql('ALTER TABLE counterparty DROP COLUMN average_delay_days');
            }
        }
    }
}
