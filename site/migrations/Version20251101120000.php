<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251101120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create money fund and fund movement tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE money_fund (id UUID NOT NULL, company_id UUID NOT NULL, name VARCHAR(150) NOT NULL, description TEXT DEFAULT NULL, currency VARCHAR(3) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL --(DC2Type:datetime_immutable)
        , PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_money_fund_company ON money_fund (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_money_fund_company_name ON money_fund (company_id, name)');
        $this->addSql('ALTER TABLE money_fund ADD CONSTRAINT FK_F1BA9A52397B2948 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE money_fund_movement (id UUID NOT NULL, company_id UUID NOT NULL, fund_id UUID NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL --(DC2Type:datetime_immutable)
        , amount_minor BIGINT NOT NULL, note TEXT DEFAULT NULL, user_id VARCHAR(64) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_money_fund_movement_company_fund ON money_fund_movement (company_id, fund_id)');
        $this->addSql('CREATE INDEX idx_money_fund_movement_company_occurred_at ON money_fund_movement (company_id, occurred_at)');
        $this->addSql('ALTER TABLE money_fund_movement ADD CONSTRAINT FK_7683BF06397B2948 FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE money_fund_movement ADD CONSTRAINT FK_7683BF066E27642C FOREIGN KEY (fund_id) REFERENCES money_fund (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE money_fund_movement DROP CONSTRAINT FK_7683BF066E27642C');
        $this->addSql('ALTER TABLE money_fund_movement DROP CONSTRAINT FK_7683BF06397B2948');
        $this->addSql('ALTER TABLE money_fund DROP CONSTRAINT FK_F1BA9A52397B2948');
        $this->addSql('DROP TABLE money_fund_movement');
        $this->addSql('DROP TABLE money_fund');
    }
}
