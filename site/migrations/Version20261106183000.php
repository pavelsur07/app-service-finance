<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add counterparty settlement documents and company-scoped journal';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE settlement_items (id UUID NOT NULL, company_id UUID NOT NULL, document_id UUID NOT NULL, counterparty_id UUID NOT NULL, bucket VARCHAR(255) NOT NULL, effective_date DATE NOT NULL, due_date DATE DEFAULT NULL, amount_amount_minor BIGINT NOT NULL, amount_currency VARCHAR(3) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_settlement_item_company_id ON settlement_items (company_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_settlement_item_document ON settlement_items (company_id, document_id)');
        $this->addSql('CREATE INDEX idx_settlement_items_party ON settlement_items (company_id, counterparty_id, amount_currency, effective_date)');
        $this->addSql('CREATE TABLE settlement_source_links (id UUID NOT NULL, company_id UUID NOT NULL, source_type VARCHAR(255) NOT NULL, source_id UUID NOT NULL, active_document_id UUID DEFAULT NULL, last_reversal_date DATE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_settlement_source_links_company_id ON settlement_source_links (company_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_settlement_source ON settlement_source_links (company_id, source_type, source_id)');
        $this->addSql('CREATE INDEX IDX_5B47BEE3979B1AD697C08D7C ON settlement_source_links (company_id, active_document_id)');
        $this->addSql('CREATE TABLE settlement_documents (id UUID NOT NULL, company_id UUID NOT NULL, request_id UUID NOT NULL, kind VARCHAR(255) NOT NULL, counterparty_id UUID NOT NULL, bucket VARCHAR(255) NOT NULL, effective_date DATE NOT NULL, due_date DATE DEFAULT NULL, reason TEXT NOT NULL, actor_user_id UUID NOT NULL, source_type VARCHAR(255) NOT NULL, source_id UUID DEFAULT NULL, source_snapshot JSON NOT NULL, reversed_document_id UUID DEFAULT NULL, status VARCHAR(255) NOT NULL, version INT NOT NULL, amount_amount_minor BIGINT NOT NULL, amount_currency VARCHAR(3) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_settlement_documents_company_id ON settlement_documents (company_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_settlement_document_request ON settlement_documents (company_id, request_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_settlement_document_reversal ON settlement_documents (company_id, reversed_document_id)');
        $this->addSql('CREATE TABLE settlement_allocations (id UUID NOT NULL, company_id UUID NOT NULL, document_id UUID NOT NULL, obligation_item_id UUID NOT NULL, payment_item_id UUID NOT NULL, effective_date DATE NOT NULL, amount_amount_minor BIGINT NOT NULL, amount_currency VARCHAR(3) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_settlement_allocations_company_id ON settlement_allocations (company_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_settlement_allocation_document ON settlement_allocations (company_id, document_id)');
        $this->addSql('CREATE INDEX IDX_61B5D0E6979B1AD6FAB135FA ON settlement_allocations (company_id, obligation_item_id)');
        $this->addSql('CREATE INDEX IDX_61B5D0E6979B1AD6B265CB4 ON settlement_allocations (company_id, payment_item_id)');
        $this->addSql('CREATE TABLE settlement_books (id UUID NOT NULL, company_id UUID NOT NULL, start_date DATE NOT NULL, initialized BOOLEAN NOT NULL, version INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_settlement_books_company_id ON settlement_books (company_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_settlement_book_company ON settlement_books (company_id)');
        $this->addSql('CREATE TABLE settlement_movements (id UUID NOT NULL, company_id UUID NOT NULL, document_id UUID NOT NULL, item_id UUID NOT NULL, bucket VARCHAR(255) NOT NULL, effective_date DATE NOT NULL, delta_amount_minor BIGINT NOT NULL, delta_currency VARCHAR(3) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_settlement_movements_company_id ON settlement_movements (company_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_settlement_movement_event ON settlement_movements (company_id, document_id, item_id, bucket)');
        $this->addSql('CREATE INDEX IDX_3148213E979B1AD6C33F7837 ON settlement_movements (company_id, document_id)');
        $this->addSql('CREATE INDEX IDX_3148213E979B1AD6126F525E ON settlement_movements (company_id, item_id)');
        $this->addSql('CREATE INDEX idx_settlement_movements_history ON settlement_movements (company_id, item_id, effective_date, id)');
        $this->addSql('ALTER TABLE settlement_items ADD CONSTRAINT fk_settlement_items_document_id FOREIGN KEY (company_id, document_id) REFERENCES settlement_documents (company_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE settlement_source_links ADD CONSTRAINT fk_settlement_source_links_active_document_id FOREIGN KEY (company_id, active_document_id) REFERENCES settlement_documents (company_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE settlement_documents ADD CONSTRAINT fk_settlement_documents_reversed_document_id FOREIGN KEY (company_id, reversed_document_id) REFERENCES settlement_documents (company_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE settlement_allocations ADD CONSTRAINT fk_settlement_allocations_document_id FOREIGN KEY (company_id, document_id) REFERENCES settlement_documents (company_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE settlement_allocations ADD CONSTRAINT fk_settlement_allocations_obligation_item_id FOREIGN KEY (company_id, obligation_item_id) REFERENCES settlement_items (company_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE settlement_allocations ADD CONSTRAINT fk_settlement_allocations_payment_item_id FOREIGN KEY (company_id, payment_item_id) REFERENCES settlement_items (company_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE settlement_movements ADD CONSTRAINT fk_settlement_movements_document_id FOREIGN KEY (company_id, document_id) REFERENCES settlement_documents (company_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE settlement_movements ADD CONSTRAINT fk_settlement_movements_item_id FOREIGN KEY (company_id, item_id) REFERENCES settlement_items (company_id, id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Financial records must be preserved; rollback application code only.');
    }
}
