<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow null PnL category for document operations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_operations ALTER COLUMN category_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_operations ALTER COLUMN category_id SET NOT NULL');
    }
}
