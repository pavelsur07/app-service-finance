<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Separate revision-bound package review confirmations; existing assignments are unchanged.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE moysklad_mapping_package_reviews (
                company_id UUID NOT NULL, connection_id UUID NOT NULL, listing_id UUID NOT NULL,
                revision INT NOT NULL, source_fingerprint VARCHAR(64) NOT NULL,
                target_fingerprint VARCHAR(64) NOT NULL, actor_user_id UUID NOT NULL,
                checked_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (company_id, connection_id, listing_id, revision, source_fingerprint, target_fingerprint),
                CONSTRAINT fk_ms_review_revision FOREIGN KEY (company_id, connection_id, listing_id, revision)
                    REFERENCES moysklad_listing_mapping_revisions (company_id, connection_id, listing_id, revision) ON DELETE RESTRICT
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Review audit must be preserved; use a forward migration.');
    }
}
