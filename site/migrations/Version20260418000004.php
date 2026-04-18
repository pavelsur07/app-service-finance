<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceAds: добавить колонку processing_error в marketplace_ad_raw_documents.
 */
final class Version20260418000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceAds: add processing_error column to marketplace_ad_raw_documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_ad_raw_documents ADD COLUMN processing_error TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_ad_raw_documents DROP COLUMN processing_error');
    }
}
