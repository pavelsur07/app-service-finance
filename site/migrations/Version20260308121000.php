<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260308121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create company members and invites tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE company_members (id UUID NOT NULL, company_id UUID NOT NULL, user_id UUID NOT NULL, role VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_members_company_user ON company_members (company_id, user_id)');
        $this->addSql('CREATE TABLE company_invites (id UUID NOT NULL, company_id UUID NOT NULL, email VARCHAR(180) NOT NULL, role VARCHAR(32) NOT NULL, token_hash VARCHAR(255) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by_id UUID NOT NULL, accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, accepted_by_user_id UUID DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_invites_token_hash ON company_invites (token_hash)');
        $this->addSql('ALTER TABLE company_members ADD CONSTRAINT fk_company_members_company FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE company_members ADD CONSTRAINT fk_company_members_user FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE company_invites ADD CONSTRAINT fk_company_invites_company FOREIGN KEY (company_id) REFERENCES "companies" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE company_invites ADD CONSTRAINT fk_company_invites_created_by FOREIGN KEY (created_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE company_invites ADD CONSTRAINT fk_company_invites_accepted_by FOREIGN KEY (accepted_by_user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE company_members');
        $this->addSql('DROP TABLE company_invites');
    }
}
