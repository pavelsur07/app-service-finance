<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260307120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove ozon/wildberries credentials from companies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies DROP COLUMN wildberries_api_key');
        $this->addSql('ALTER TABLE companies DROP COLUMN ozon_seller_id');
        $this->addSql('ALTER TABLE companies DROP COLUMN ozon_api_key');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD wildberries_api_key TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE companies ADD ozon_seller_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE companies ADD ozon_api_key VARCHAR(255) DEFAULT NULL');
    }
}
