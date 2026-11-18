<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Company: company_tax_profiles.tax_rate_percent — ставка налога режима (переменная TAX_RATE формул ОПиУ).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company_tax_profiles ADD tax_rate_percent NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE company_tax_profiles ADD CONSTRAINT chk_company_tax_profiles_tax_rate CHECK (tax_rate_percent IS NULL OR tax_rate_percent BETWEEN 0 AND 100)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company_tax_profiles DROP CONSTRAINT chk_company_tax_profiles_tax_rate');
        $this->addSql('ALTER TABLE company_tax_profiles DROP tax_rate_percent');
    }
}
