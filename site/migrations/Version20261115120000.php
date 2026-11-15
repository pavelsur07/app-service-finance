<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceWb (этап 8.4 marketplace-profit-pipeline): ревизия рекламы WB (`wb.ads.daily`), по которой
 * переведён день. Колонка с умолчанием 0 — существующие дни переведутся заново при первой
 * рекламе; откат удаляет колонку без потери данных канона.
 */
final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceWb: mwb_day.ads_revision';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mwb_day ADD ads_revision INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mwb_day DROP ads_revision');
    }
}
