<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250928191315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add finance_lock_before to company';
    }

    public function up(Schema $schema): void
    {
        // проверьте точное имя таблицы компаний в вашей БД
        $this->addSql('ALTER TABLE companies ADD finance_lock_before DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies DROP finance_lock_before');
    }
}
