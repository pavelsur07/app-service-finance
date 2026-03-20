<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add return_commission fields to marketplace_ozon_realizations for "Возврат с СПП" amount source';
    }

    public function up(Schema $schema): void
    {
        // Цена единицы возврата покупателю с учётом СПП (return_commission.price_per_instance)
        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_ozon_realizations
                ADD COLUMN return_price_per_instance NUMERIC(12, 2) DEFAULT NULL
        SQL);

        // Количество возвращённых единиц (return_commission.quantity)
        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_ozon_realizations
                ADD COLUMN return_quantity INTEGER DEFAULT NULL
        SQL);

        // Итого сумма возврата = return_price_per_instance × return_quantity
        $this->addSql(<<<'SQL'
            ALTER TABLE marketplace_ozon_realizations
                ADD COLUMN return_amount NUMERIC(12, 2) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_ozon_realizations DROP COLUMN IF EXISTS return_price_per_instance');
        $this->addSql('ALTER TABLE marketplace_ozon_realizations DROP COLUMN IF EXISTS return_quantity');
        $this->addSql('ALTER TABLE marketplace_ozon_realizations DROP COLUMN IF EXISTS return_amount');
    }
}
