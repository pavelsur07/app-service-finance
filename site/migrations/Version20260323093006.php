<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323093006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique constraint: one product per marketplace per company in marketplace_listings';
    }

    public function up(Schema $schema): void
    {
        // Оставляем первую привязку (по created_at), остальные дубли отвязываем
        $this->addSql("
            UPDATE marketplace_listings SET product_id = NULL
            WHERE id NOT IN (
                SELECT DISTINCT ON (company_id, marketplace, product_id) id
                FROM marketplace_listings
                WHERE product_id IS NOT NULL
                ORDER BY company_id, marketplace, product_id, created_at ASC
            )
            AND product_id IS NOT NULL
        ");

        $this->addSql('
            CREATE UNIQUE INDEX uniq_company_marketplace_product
            ON marketplace_listings (company_id, marketplace, product_id)
            WHERE product_id IS NOT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_company_marketplace_product');
    }
}
