<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BankSync: bank_sync_connection.external_subject и consent_expires_at (Alfa ID, сверка согласий)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_sync_connection ADD external_subject VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE bank_sync_connection ADD consent_expires_at DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_sync_connection DROP consent_expires_at');
        $this->addSql('ALTER TABLE bank_sync_connection DROP external_subject');
    }
}
