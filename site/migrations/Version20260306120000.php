<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260306120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure aggregation columns exist on wildberries commissioner reports after table creation';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        try {
            $table = $schemaManager->introspectTable('wildberries_commissioner_xlsx_reports');
        } catch (\Throwable $exception) {
            return;
        }

        if (!$table->hasColumn('aggregation_status')) {
            $this->connection->executeStatement(
                "ALTER TABLE wildberries_commissioner_xlsx_reports ADD COLUMN IF NOT EXISTS aggregation_status VARCHAR(32) DEFAULT 'not_calculated' NOT NULL"
            );
        }

        if (!$table->hasColumn('aggregation_errors_json')) {
            $this->connection->executeStatement(
                'ALTER TABLE wildberries_commissioner_xlsx_reports ADD COLUMN IF NOT EXISTS aggregation_errors_json JSON DEFAULT NULL'
            );
        }
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        try {
            $table = $schemaManager->introspectTable('wildberries_commissioner_xlsx_reports');
        } catch (\Throwable $exception) {
            return;
        }

        if ($table->hasColumn('aggregation_errors_json')) {
            $this->addSql('ALTER TABLE wildberries_commissioner_xlsx_reports DROP COLUMN aggregation_errors_json');
        }

        if ($table->hasColumn('aggregation_status')) {
            $this->addSql('ALTER TABLE wildberries_commissioner_xlsx_reports DROP COLUMN aggregation_status');
        }
    }
}
