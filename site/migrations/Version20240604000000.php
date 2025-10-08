<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240604000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite index for ozon orders incremental sync';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_company_scheme_ozon_updated_at ON ozon_orders (company_id, scheme, ozon_updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_company_scheme_ozon_updated_at');
    }
}
