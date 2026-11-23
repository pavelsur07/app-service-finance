<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceOzon (этап 6 marketplace-profit-pipeline): ревизия статистики рекламы, по которой
 * переведён день. Колонка с умолчанием 0 — существующие дни переведутся заново при первой
 * статистике; откат удаляет колонку без потери данных канона.
 */
final class Version20261114180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceOzon: moz_day.ads_revision';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE moz_day ADD ads_revision INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE moz_day DROP ads_revision');
    }
}
