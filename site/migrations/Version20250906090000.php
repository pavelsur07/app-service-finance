<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250906090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add inn field to companies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD inn VARCHAR(12) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies DROP inn');
    }
}
