<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250725153117 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE "companies" (id UUID NOT NULL, user_id UUID NOT NULL, name VARCHAR(255) NOT NULL, wildberries_api_key VARCHAR(255) DEFAULT NULL, ozon_seller_id VARCHAR(255) DEFAULT NULL, ozon_api_key VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_8244AA3AA76ED395 ON "companies" (user_id)');
        $this->addSql('ALTER TABLE "companies" ADD CONSTRAINT FK_8244AA3AA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE "companies" DROP CONSTRAINT FK_8244AA3AA76ED395');
        $this->addSql('DROP TABLE "companies"');
    }
}
