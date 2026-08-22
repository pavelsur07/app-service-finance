<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260822121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add company-level minimum balance as Money';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD minimum_balance_amount_minor BIGINT DEFAULT 0 NOT NULL');
        $this->addSql("COMMENT ON COLUMN companies.minimum_balance_amount_minor IS '(DC2Type:money_amount_minor)'");
        $this->addSql("ALTER TABLE companies ADD minimum_balance_currency VARCHAR(3) DEFAULT 'RUB' NOT NULL");
        $this->addSql('ALTER TABLE companies ALTER minimum_balance_amount_minor DROP DEFAULT');
        $this->addSql('ALTER TABLE companies ALTER minimum_balance_currency DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies DROP minimum_balance_amount_minor');
        $this->addSql('ALTER TABLE companies DROP minimum_balance_currency');
    }
}
