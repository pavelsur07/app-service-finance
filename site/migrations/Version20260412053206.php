<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412053206 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceAds: create marketplace_ad_raw_documents, marketplace_ad_documents, marketplace_ad_document_lines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_ad_raw_documents (
                id VARCHAR(36) NOT NULL,
                company_id VARCHAR(36) NOT NULL,
                marketplace VARCHAR(50) NOT NULL,
                report_date DATE NOT NULL,
                loaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                raw_payload TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT uq_ad_raw_document_company_marketplace_date UNIQUE (company_id, marketplace, report_date)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_ad_raw_document_company ON marketplace_ad_raw_documents (company_id)');
        $this->addSql('CREATE INDEX idx_ad_raw_document_company_marketplace ON marketplace_ad_raw_documents (company_id, marketplace)');
        $this->addSql("COMMENT ON COLUMN marketplace_ad_raw_documents.report_date IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_raw_documents.loaded_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_raw_documents.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_raw_documents.updated_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_ad_documents (
                id VARCHAR(36) NOT NULL,
                company_id VARCHAR(36) NOT NULL,
                marketplace VARCHAR(50) NOT NULL,
                report_date DATE NOT NULL,
                campaign_id VARCHAR(255) NOT NULL,
                campaign_name VARCHAR(255) NOT NULL,
                parent_sku VARCHAR(255) NOT NULL,
                total_cost NUMERIC(14, 2) NOT NULL,
                total_impressions INT NOT NULL,
                total_clicks INT NOT NULL,
                ad_raw_document_id VARCHAR(36) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT uq_ad_document_company_marketplace_date_campaign_sku
                    UNIQUE (company_id, marketplace, report_date, campaign_id, parent_sku)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_ad_document_company ON marketplace_ad_documents (company_id)');
        $this->addSql('CREATE INDEX idx_ad_document_company_date ON marketplace_ad_documents (company_id, report_date)');
        $this->addSql('CREATE INDEX idx_ad_document_raw ON marketplace_ad_documents (ad_raw_document_id)');
        $this->addSql("COMMENT ON COLUMN marketplace_ad_documents.report_date IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_ad_documents.created_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
            CREATE TABLE marketplace_ad_document_lines (
                id VARCHAR(36) NOT NULL,
                ad_document_id VARCHAR(36) NOT NULL,
                listing_id VARCHAR(36) NOT NULL,
                share_percent NUMERIC(7, 4) NOT NULL,
                cost NUMERIC(14, 2) NOT NULL,
                impressions INT NOT NULL,
                clicks INT NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_ad_document_line_document
                    FOREIGN KEY (ad_document_id)
                    REFERENCES marketplace_ad_documents (id)
                    ON DELETE CASCADE
                    NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_ad_document_line_document ON marketplace_ad_document_lines (ad_document_id)');
        $this->addSql('CREATE INDEX idx_ad_document_line_listing ON marketplace_ad_document_lines (listing_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_ad_document_lines');
        $this->addSql('DROP TABLE marketplace_ad_documents');
        $this->addSql('DROP TABLE marketplace_ad_raw_documents');
    }
}
