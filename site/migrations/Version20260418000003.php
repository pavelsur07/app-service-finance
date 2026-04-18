<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceAds: DROP COLUMN chunks_completed из marketplace_ad_load_jobs.
 *
 * Прогресс чанков теперь хранится в marketplace_ad_chunk_progress
 * (идемпотентная запись на каждый чанк), а не в счётчике на job'е.
 */
final class Version20260418000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceAds: drop chunks_completed column from marketplace_ad_load_jobs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_ad_load_jobs DROP COLUMN chunks_completed');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_ad_load_jobs ADD COLUMN chunks_completed INT NOT NULL DEFAULT 0');
    }
}
