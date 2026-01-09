<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250726093158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE "ozon_products" (id UUID NOT NULL, company_id UUID NOT NULL, ozon_sku VARCHAR(255) NOT NULL, manufacturer_sku VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, price DOUBLE PRECISION NOT NULL, image_url VARCHAR(1024) DEFAULT NULL, archived BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_9FFB7DD5979B1AD6 ON "ozon_products" (company_id)');
        $this->addSql('ALTER TABLE "ozon_products" ADD CONSTRAINT FK_9FFB7DD5979B1AD6 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE "ozon_products" DROP CONSTRAINT FK_9FFB7DD5979B1AD6');
        $this->addSql('DROP TABLE "ozon_products"');
    }
}
