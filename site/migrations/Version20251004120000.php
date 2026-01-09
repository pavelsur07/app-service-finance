<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251004120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional counterparty relation to documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documents ADD counterparty_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_DOCUMENT_COUNTERPARTY ON documents (counterparty_id)');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_DOCUMENT_COUNTERPARTY FOREIGN KEY (counterparty_id) REFERENCES "counterparty" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documents DROP CONSTRAINT FK_DOCUMENT_COUNTERPARTY');
        $this->addSql('DROP INDEX IDX_DOCUMENT_COUNTERPARTY');
        $this->addSql('ALTER TABLE documents DROP COLUMN counterparty_id');
    }
}
