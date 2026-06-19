<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace listing references to ingestion financial transactions';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('ALTER TABLE ingest_financial_transactions ADD listing_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE ingest_financial_transactions ADD listing_sku VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_ftx_company_listing ON ingest_financial_transactions (company_id, listing_id)');
        $this->addSql('CREATE INDEX idx_marketplace_listing_company_supplier_sku ON marketplace_listings (company_id, marketplace, supplier_sku)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('DROP INDEX idx_marketplace_listing_company_supplier_sku');
        $this->addSql('DROP INDEX idx_ftx_company_listing');
        $this->addSql('ALTER TABLE ingest_financial_transactions DROP listing_sku');
        $this->addSql('ALTER TABLE ingest_financial_transactions DROP listing_id');
    }
}
