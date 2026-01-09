<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251123090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add enum-backed document type with default value and update existing documents';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $allowedTypes = "'DEAL_SALE', 'PAYROLL', 'TAXES', 'LOANS', 'OBLIGATIONS', 'ASSETS', 'CASH', 'CASHFLOW_EXPENSE', 'OTHER'";

        $this->addSql("ALTER TABLE documents ADD COLUMN IF NOT EXISTS type VARCHAR(255) DEFAULT 'OTHER'");

        $this->addSql("UPDATE documents SET type = 'OTHER'");

        if ('postgresql' === $platform) {
            $this->addSql('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_type_enum_check');
            $this->addSql("ALTER TABLE documents ALTER COLUMN type SET DEFAULT 'OTHER'");
            $this->addSql("ALTER TABLE documents ADD CONSTRAINT documents_type_enum_check CHECK (type IN ($allowedTypes))");
            $this->addSql('ALTER TABLE documents ALTER COLUMN type SET NOT NULL');

            return;
        }

        $this->addSql("ALTER TABLE documents ALTER COLUMN type SET DEFAULT 'OTHER'");
        $this->addSql('ALTER TABLE documents ALTER COLUMN type SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $legacyTypes = "'SALES', 'PAYROLL', 'LIABILITIES', 'TAXES', 'PROPERTY', 'LOANS', 'OTHER'";

        $this->addSql("UPDATE documents SET type = 'OTHER' WHERE type NOT IN ($legacyTypes)");

        if ('postgresql' === $platform) {
            $this->addSql('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_type_enum_check');
            $this->addSql('ALTER TABLE documents ALTER COLUMN type DROP DEFAULT');
            $this->addSql("ALTER TABLE documents ADD CONSTRAINT documents_type_enum_check CHECK (type IN ($legacyTypes))");

            return;
        }

        $this->addSql('ALTER TABLE documents ALTER COLUMN type DROP DEFAULT');
    }
}
