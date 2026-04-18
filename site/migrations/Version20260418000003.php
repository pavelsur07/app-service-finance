<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceAds: переводит учёт обработки рекламных документов с counter-based
 * модели на AdLoadJob (processed_days/failed_days) на per-document статус
 * FAILED у AdRawDocument.
 *
 * Мотивация (PR #1572, round 2):
 *   Счётчики processed_days/failed_days на AdLoadJob инкрементировались через
 *   raw DBAL UPDATE из разных воркеров и из catch-блока ProcessAdRawDocumentHandler.
 *   При retry Messenger'а failed_days overshoot'ил (каждая попытка +1), а
 *   «расхождение» с COUNT(AdRawDocument) ловилось только на финализации — в
 *   error-branch tryFinalizeJob приходилось специально отключать, иначе job
 *   помечался FAILED раньше, чем retry мог завершить работу.
 *
 *   Решение: факт обработки/ошибки живёт на самом документе как enum-статус
 *   (PROCESSED / FAILED). Финализация считает COUNT(PROCESSED) + COUNT(FAILED)
 *   и сравнивает с total COUNT в диапазоне — источник правды идемпотентен и
 *   не требует обратных декрементов при retry.
 *
 * Изменения:
 *   - marketplace_ad_raw_documents.processing_error TEXT NULL (причина FAILED);
 *   - marketplace_ad_load_jobs: DROP COLUMN processed_days, failed_days.
 */
final class Version20260418000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceAds: move processing state from AdLoadJob counters to AdRawDocument.status (FAILED)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_ad_raw_documents ADD COLUMN processing_error TEXT DEFAULT NULL');

        $this->addSql('ALTER TABLE marketplace_ad_load_jobs DROP COLUMN processed_days');
        $this->addSql('ALTER TABLE marketplace_ad_load_jobs DROP COLUMN failed_days');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_ad_load_jobs ADD COLUMN processed_days INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE marketplace_ad_load_jobs ADD COLUMN failed_days INT NOT NULL DEFAULT 0');

        $this->addSql('ALTER TABLE marketplace_ad_raw_documents DROP COLUMN processing_error');
    }
}
