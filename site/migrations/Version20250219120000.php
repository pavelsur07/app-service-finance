<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250219120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Добавляет updated_at в bot_links для синхронизации с сущностью BotLink.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('bot_links')) {
            return;
        }

        // Добавляем колонку updated_at, приводим существующие записи к created_at и запрещаем NULL
        $this->addSql('ALTER TABLE bot_links ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('UPDATE bot_links SET updated_at = created_at WHERE updated_at IS NULL');
        $this->addSql('ALTER TABLE bot_links ALTER COLUMN updated_at SET NOT NULL');
        $this->addSql("COMMENT ON COLUMN bot_links.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('bot_links')) {
            return;
        }

        // Откатываем добавление колонки updated_at, возвращая схему в исходное состояние
        $this->addSql('ALTER TABLE bot_links DROP COLUMN updated_at');
    }
}
