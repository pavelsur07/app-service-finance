<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323093004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change product_purchase_prices.price_amount from bigint (kopecks) to decimal(10,2) (rubles)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_purchase_prices ALTER COLUMN price_amount TYPE NUMERIC(10,2)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_purchase_prices ALTER COLUMN price_amount TYPE BIGINT');
    }
}
