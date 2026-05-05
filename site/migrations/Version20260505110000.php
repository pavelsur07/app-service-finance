<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace_ozon_transaction_totals_checks table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_ozon_transaction_totals_checks (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                raw_document_id UUID NOT NULL,
                period_from DATE NOT NULL,
                period_to DATE NOT NULL,
                status VARCHAR(16) NOT NULL,
                check_type VARCHAR(64) NOT NULL DEFAULT 'transaction_totals',
                detail_totals JSON NOT NULL,
                ozon_totals JSON NOT NULL,
                diffs JSON NOT NULL,
                tolerance VARCHAR(32) NOT NULL,
                checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                error_message TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_ozon_totals_check_company_period ON marketplace_ozon_transaction_totals_checks (company_id, period_from, period_to)');
        $this->addSql('CREATE INDEX idx_ozon_totals_check_raw_document ON marketplace_ozon_transaction_totals_checks (company_id, raw_document_id)');
        $this->addSql('CREATE INDEX idx_ozon_totals_check_status ON marketplace_ozon_transaction_totals_checks (company_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS marketplace_ozon_transaction_totals_checks');
    }
}
