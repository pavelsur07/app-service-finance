<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323093005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cost_price to marketplace_returns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_returns ADD COLUMN cost_price NUMERIC(10,2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_returns DROP COLUMN cost_price');
    }
}
