<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace_created_at and last_seen_at to marketplace_listings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_listings ADD marketplace_created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_listings ADD last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN marketplace_listings.marketplace_created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN marketplace_listings.last_seen_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_listings DROP marketplace_created_at');
        $this->addSql('ALTER TABLE marketplace_listings DROP last_seen_at');
    }
}
