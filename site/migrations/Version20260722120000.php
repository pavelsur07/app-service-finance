<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260722120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace listing tags and listing-tag assignments';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $this->addSql(<<<'SQL'
CREATE TABLE marketplace_listing_tags (
    id         UUID NOT NULL,
    company_id UUID NOT NULL,
    name       VARCHAR(50) NOT NULL,
    slug       VARCHAR(50) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_listing_tag_company_slug ON marketplace_listing_tags (company_id, slug)');

        $this->addSql(<<<'SQL'
CREATE TABLE marketplace_listing_tag_assignments (
    listing_id UUID NOT NULL,
    tag_id     UUID NOT NULL,
    company_id UUID NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (listing_id, tag_id),
    CONSTRAINT fk_listing_tag_assign_listing FOREIGN KEY (listing_id)
        REFERENCES marketplace_listings (id) ON DELETE CASCADE,
    CONSTRAINT fk_listing_tag_assign_tag FOREIGN KEY (tag_id)
        REFERENCES marketplace_listing_tags (id) ON DELETE CASCADE
)
SQL);
        $this->addSql('CREATE INDEX idx_listing_tag_assign_company_tag ON marketplace_listing_tag_assignments (company_id, tag_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS marketplace_listing_tag_assignments');
        $this->addSql('DROP TABLE IF EXISTS marketplace_listing_tags');
    }
}
