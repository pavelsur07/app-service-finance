<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250907000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add PL categories and documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pl_categories (id UUID NOT NULL, company_id UUID NOT NULL, parent_id UUID DEFAULT NULL, name VARCHAR(255) NOT NULL, level INT NOT NULL, sort_order INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_PL_CATEGORY_COMPANY ON pl_categories (company_id)');
        $this->addSql('CREATE INDEX IDX_PL_CATEGORY_PARENT ON pl_categories (parent_id)');
        $this->addSql('ALTER TABLE pl_categories ADD CONSTRAINT FK_PL_CATEGORY_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE pl_categories ADD CONSTRAINT FK_PL_CATEGORY_PARENT FOREIGN KEY (parent_id) REFERENCES pl_categories (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE documents (id UUID NOT NULL, company_id UUID NOT NULL, date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, number VARCHAR(255) DEFAULT NULL, type VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_DOCUMENT_COMPANY ON documents (company_id)');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_DOCUMENT_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE document_operations (id UUID NOT NULL, document_id UUID NOT NULL, category_id UUID NOT NULL, counterparty_id UUID DEFAULT NULL, amount NUMERIC(15, 2) NOT NULL, comment VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_DOC_OPER_DOCUMENT ON document_operations (document_id)');
        $this->addSql('CREATE INDEX IDX_DOC_OPER_CATEGORY ON document_operations (category_id)');
        $this->addSql('CREATE INDEX IDX_DOC_OPER_COUNTERPARTY ON document_operations (counterparty_id)');
        $this->addSql('ALTER TABLE document_operations ADD CONSTRAINT FK_DOC_OPER_DOCUMENT FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE document_operations ADD CONSTRAINT FK_DOC_OPER_CATEGORY FOREIGN KEY (category_id) REFERENCES pl_categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE document_operations ADD CONSTRAINT FK_DOC_OPER_COUNTERPARTY FOREIGN KEY (counterparty_id) REFERENCES counterparties (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_operations DROP CONSTRAINT FK_DOC_OPER_DOCUMENT');
        $this->addSql('ALTER TABLE document_operations DROP CONSTRAINT FK_DOC_OPER_CATEGORY');
        $this->addSql('ALTER TABLE document_operations DROP CONSTRAINT FK_DOC_OPER_COUNTERPARTY');
        $this->addSql('ALTER TABLE documents DROP CONSTRAINT FK_DOCUMENT_COMPANY');
        $this->addSql('ALTER TABLE pl_categories DROP CONSTRAINT FK_PL_CATEGORY_COMPANY');
        $this->addSql('ALTER TABLE pl_categories DROP CONSTRAINT FK_PL_CATEGORY_PARENT');
        $this->addSql('DROP TABLE document_operations');
        $this->addSql('DROP TABLE documents');
        $this->addSql('DROP TABLE pl_categories');
    }
}
