<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceAds: удалить мёртвые counter-колонки processed_days / failed_days
 * из marketplace_ad_load_jobs.
 *
 * Финализация job'а перешла на per-document FAILED-статус AdRawDocument:
 * успех/неуспех задания считается через COUNT по marketplace_ad_raw_documents,
 * отдельные диагностические счётчики в job'е больше не используются.
 */
final class Version20260419080739 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceAds: drop dead processed_days / failed_days columns from marketplace_ad_load_jobs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_ad_load_jobs DROP COLUMN processed_days');
        $this->addSql('ALTER TABLE marketplace_ad_load_jobs DROP COLUMN failed_days');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_ad_load_jobs ADD COLUMN processed_days INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE marketplace_ad_load_jobs ADD COLUMN failed_days INT NOT NULL DEFAULT 0');
    }
}
