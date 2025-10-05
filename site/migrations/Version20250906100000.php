<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250906100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change wildberries_api_key column type to TEXT';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "companies" ALTER COLUMN wildberries_api_key TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "companies" ALTER COLUMN wildberries_api_key TYPE VARCHAR(255)');
    }
}
