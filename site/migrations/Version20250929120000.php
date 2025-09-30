<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Enum\DocumentType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize document type values to DocumentType enum and add supporting index/check';
    }

    public function up(Schema $schema): void
    {
        $this->normalizeTypes();

        if ('postgresql' === $this->connection->getDatabasePlatform()->getName()) {
            $this->addSql(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'documents_type_enum_check'
    ) THEN
        ALTER TABLE documents
            ADD CONSTRAINT documents_type_enum_check CHECK (type IN (
                'SERVICE_ACT',
                'SALES_DELIVERY_NOTE',
                'COMMISSION_REPORT',
                'PURCHASE_INVOICE',
                'ACCEPTANCE_ACT',
                'WRITE_OFF_ACT',
                'INVENTORY_SHEET',
                'LOAN_AND_SCHEDULE',
                'PAYROLL_ACCRUAL',
                'DEPRECIATION',
                'TAXES_AND_CONTRIBUTIONS',
                'FX_PENALTIES',
                'SALES_OR_PURCHASE_RETURN',
                'OTHER'
            ));
    END IF;
END $$;
SQL);
        }

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_documents_company_type_date ON documents (company_id, type, date DESC)');
    }

    public function down(Schema $schema): void
    {
        if ('postgresql' === $this->connection->getDatabasePlatform()->getName()) {
            $this->addSql(<<<'SQL'
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'documents_type_enum_check'
    ) THEN
        ALTER TABLE documents DROP CONSTRAINT documents_type_enum_check;
    END IF;
END $$;
SQL);
        }

        $this->addSql('DROP INDEX IF EXISTS idx_documents_company_type_date');
    }

    private function normalizeTypes(): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, type FROM documents');

        foreach ($rows as $row) {
            $current = $row['type'];
            $normalized = $current === null || '' === trim((string) $current)
                ? DocumentType::OTHER->value
                : DocumentType::fromLegacy((string) $current)->value;

            if ($normalized !== $current) {
                $this->connection->update('documents', ['type' => $normalized], ['id' => $row['id']]);
            }
        }
    }
}
