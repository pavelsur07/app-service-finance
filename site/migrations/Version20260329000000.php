<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Добавляет колонку settings в marketplace_month_closes.
 * Используется для хранения результата сверки с xlsx без новой таблицы.
 */
final class Version20260329000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add settings JSON column to marketplace_month_closes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_month_closes ADD settings JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_month_closes DROP settings');
    }
}
