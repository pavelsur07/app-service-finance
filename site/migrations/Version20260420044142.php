<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MarketplaceAds: добавить поля для bronze-хранения сырого ответа рекламных API
 * в marketplace_ad_raw_documents.
 *
 * Поля:
 *  - storage_path    — относительный путь к файлу внутри storageRoot
 *  - file_hash       — sha256 содержимого файла (hex, 64 символа)
 *  - file_size_bytes — размер файла в байтах
 *
 * Частичный индекс idx_ad_raw_docs_file_hash нужен для поиска дубликатов
 * по содержимому и только для документов с уже сохранённым файлом.
 */
final class Version20260420044142 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MarketplaceAds: add storage_path / file_hash / file_size_bytes columns to marketplace_ad_raw_documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_ad_raw_documents ADD COLUMN storage_path VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_ad_raw_documents ADD COLUMN file_hash CHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_ad_raw_documents ADD COLUMN file_size_bytes BIGINT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_ad_raw_docs_file_hash ON marketplace_ad_raw_documents (file_hash) WHERE file_hash IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_ad_raw_docs_file_hash');
        $this->addSql('ALTER TABLE marketplace_ad_raw_documents DROP COLUMN storage_path');
        $this->addSql('ALTER TABLE marketplace_ad_raw_documents DROP COLUMN file_hash');
        $this->addSql('ALTER TABLE marketplace_ad_raw_documents DROP COLUMN file_size_bytes');
    }
}
