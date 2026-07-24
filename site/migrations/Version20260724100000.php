<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add idempotent source key to marketplace ad raw documents';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql('ALTER TABLE marketplace_ad_raw_documents ADD source_key VARCHAR(255) DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE marketplace_ad_raw_documents
             ADD CONSTRAINT uq_ad_raw_document_company_marketplace_source
             UNIQUE (company_id, marketplace, source_key)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE marketplace_ad_raw_documents
             DROP CONSTRAINT uq_ad_raw_document_company_marketplace_source',
        );
        $this->addSql('ALTER TABLE marketplace_ad_raw_documents DROP source_key');
    }
}
