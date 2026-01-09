<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize document type values to predefined list and add supporting index/check';
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
                'SALES',
                'PAYROLL',
                'LIABILITIES',
                'TAXES',
                'PROPERTY',
                'LOANS',
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
                ? 'OTHER'
                : $this->mapLegacyType((string) $current);

            if ($normalized !== $current) {
                $this->connection->update('documents', ['type' => $normalized], ['id' => $row['id']]);
            }
        }
    }
    private function mapLegacyType(string $value): string
    {
        $normalized = strtoupper(trim($value));

        if (in_array($normalized, self::KNOWN_TYPES, true)) {
            return $normalized;
        }

        return self::LEGACY_TYPE_MAP[$normalized] ?? 'OTHER';
    }

    private const KNOWN_TYPES = [
        'SALES',
        'PAYROLL',
        'LIABILITIES',
        'TAXES',
        'PROPERTY',
        'LOANS',
        'OTHER',
    ];

    private const LEGACY_TYPE_MAP = [
        'НАКЛАДНАЯ' => 'SALES',
        'ТОРГ-12' => 'SALES',
        'УПД' => 'SALES',
        'СЧЕТ-ФАКТУРА' => 'SALES',
        'РЕАЛИЗАЦИЯ' => 'SALES',
        'ЧЕК' => 'SALES',
        'ККТ' => 'SALES',
        'АКТ' => 'SALES',
        'АКТ ВЫПОЛНЕННЫХ РАБОТ' => 'SALES',
        'АКТ ОКАЗАННЫХ УСЛУГ' => 'SALES',
        'SERVICE_ACT' => 'SALES',
        'ОТЧЕТ КОМИССИОНЕРА' => 'SALES',
        'ОТЧЕТ АГЕНТА' => 'SALES',
        'ОТЧЕТ МАРКЕТПЛЕЙСА' => 'SALES',
        'WB' => 'SALES',
        'OZON' => 'SALES',
        'YANDEX' => 'SALES',
        'ВОЗВРАТ ОТ ПОКУПАТЕЛЯ' => 'SALES',
        'ВОЗВРАТ ПОСТАВЩИКУ' => 'SALES',
        'ВОЗВРАТ' => 'SALES',
        'ВЕДОМОСТЬ ЗП' => 'PAYROLL',
        'ЗАРПЛАТА' => 'PAYROLL',
        'НАЧИСЛЕНИЕ ЗАРПЛАТЫ' => 'PAYROLL',
        'СЧЕТ ПОСТАВЩИКА' => 'LIABILITIES',
        'СФ ПОСТАВЩИКА' => 'LIABILITIES',
        'КУРСОВЫЕ РАЗНИЦЫ' => 'LIABILITIES',
        'ШТРАФЫ' => 'LIABILITIES',
        'ПЕНИ' => 'LIABILITIES',
        'НАЛОГИ' => 'TAXES',
        'ВЗНОСЫ' => 'TAXES',
        'НАЧИСЛЕНИЕ НАЛОГОВ' => 'TAXES',
        'НАЧИСЛЕНИЕ ВЗНОСОВ' => 'TAXES',
        'АКТ ПОДРЯДЧИКА' => 'PROPERTY',
        'ПОШИВ' => 'PROPERTY',
        'АКТ ПРИЕМКИ-ПЕРЕДАЧИ' => 'PROPERTY',
        'СПИСАНИЕ МАТЕРИАЛОВ' => 'PROPERTY',
        'АКТ СПИСАНИЯ' => 'PROPERTY',
        'ИНВЕНТАРИЗАЦИЯ' => 'PROPERTY',
        'ИНВЕНТАРИЗАЦИОННАЯ ОПИСЬ' => 'PROPERTY',
        'АМОРТИЗАЦИЯ' => 'PROPERTY',
        'АМОРТИЗАЦИЯ ОС' => 'PROPERTY',
        'ПРОЦЕНТЫ ПО КРЕДИТУ' => 'LOANS',
        'КРЕДИТНЫЙ ДОГОВОР' => 'LOANS',
        'ГРАФИК ПЛАТЕЖЕЙ' => 'LOANS',
    ];
}
