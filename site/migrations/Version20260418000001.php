<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceAds: добавляет счётчики чанков `chunks_total` / `chunks_completed`
 * в таблицу `marketplace_ad_load_jobs`.
 *
 * Нужны для корректной семантики завершения job'а: после Коммита 3 стало
 * очевидно, что loaded_days не является надёжным признаком «все чанки
 * отработали» (Ozon легитимно может вернуть меньше дней, чем запросили,
 * и loaded_days в этом случае считается по покрытию чанка).
 *
 * chunks_total выставляется один раз при старте job'а в
 * {@see \App\MarketplaceAds\MessageHandler\LoadOzonAdStatisticsRangeHandler}.
 * chunks_completed инкрементируется атомарно после каждого успешного чанка
 * в {@see \App\MarketplaceAds\MessageHandler\FetchOzonAdStatisticsHandler}.
 */
final class Version20260418000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceAds: add chunks_total / chunks_completed counters to marketplace_ad_load_jobs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_ad_load_jobs
                ADD COLUMN chunks_total INT NOT NULL DEFAULT 0,
                ADD COLUMN chunks_completed INT NOT NULL DEFAULT 0
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_ad_load_jobs
                DROP COLUMN chunks_completed,
                DROP COLUMN chunks_total
        SQL);
    }
}
