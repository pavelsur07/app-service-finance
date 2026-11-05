<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Удаление компании (docs/tasks/company-deletion, этап 1): пометка «удаление запрошено».
 *
 * Две nullable-колонки без значения по умолчанию — существующие строки не переписываются,
 * блокировка таблицы мгновенная; данные не затрагиваются; обратима.
 */
final class Version20261105090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Company: deletion_requested_at / deletion_requested_by';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD deletion_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE companies ADD deletion_requested_by UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies DROP deletion_requested_at');
        $this->addSql('ALTER TABLE companies DROP deletion_requested_by');
    }
}
