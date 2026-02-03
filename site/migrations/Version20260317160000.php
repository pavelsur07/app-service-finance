<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create deal charges table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deal_charges (id UUID NOT NULL, deal_id UUID NOT NULL, charge_type_id UUID NOT NULL, recognized_at DATE NOT NULL, amount NUMERIC(18, 2) NOT NULL, comment VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_deal_charge_deal ON deal_charges (deal_id)');
        $this->addSql('CREATE INDEX idx_deal_charge_charge_type ON deal_charges (charge_type_id)');
        $this->addSql('ALTER TABLE deal_charges ADD CONSTRAINT FK_DEAL_CHARGES_DEAL FOREIGN KEY (deal_id) REFERENCES deals (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE deal_charges ADD CONSTRAINT FK_DEAL_CHARGES_CHARGE_TYPE FOREIGN KEY (charge_type_id) REFERENCES deal_charge_types (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deal_charges DROP CONSTRAINT FK_DEAL_CHARGES_DEAL');
        $this->addSql('ALTER TABLE deal_charges DROP CONSTRAINT FK_DEAL_CHARGES_CHARGE_TYPE');
        $this->addSql('DROP TABLE deal_charges');
    }
}
