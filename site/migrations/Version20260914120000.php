<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create autonomous company-scoped Balance ledger, preserving legacy categories and links.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE balance_books (
                id UUID NOT NULL PRIMARY KEY,
                company_id UUID NOT NULL,
                currency VARCHAR(3) DEFAULT NULL,
                start_date DATE DEFAULT NULL,
                initialized BOOLEAN DEFAULT FALSE NOT NULL,
                version INT DEFAULT 0 NOT NULL,
                next_document_number BIGINT DEFAULT 1 NOT NULL,
                next_posting_sequence BIGINT DEFAULT 1 NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT uniq_balance_book_company UNIQUE (company_id),
                CONSTRAINT chk_balance_book_state CHECK (NOT initialized OR (currency IS NOT NULL AND start_date IS NOT NULL)),
                CONSTRAINT chk_balance_book_counters CHECK (version >= 0 AND next_document_number > 0 AND next_posting_sequence > 0)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE balance_articles (
                id UUID NOT NULL PRIMARY KEY,
                company_id UUID NOT NULL,
                name VARCHAR(255) NOT NULL,
                code VARCHAR(64) DEFAULT NULL,
                type VARCHAR(50) NOT NULL,
                parent_id UUID DEFAULT NULL,
                level INT DEFAULT 1 NOT NULL,
                sort_order INT DEFAULT 0 NOT NULL,
                is_visible BOOLEAN DEFAULT TRUE NOT NULL,
                kind VARCHAR(20) DEFAULT 'article' NOT NULL,
                is_archived BOOLEAN DEFAULT FALSE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT uniq_balance_article_company_id UNIQUE (company_id, id),
                CONSTRAINT uniq_balance_article_code UNIQUE (company_id, code),
                CONSTRAINT chk_balance_article_type CHECK (type IN ('asset', 'passive')),
                CONSTRAINT chk_balance_article_kind CHECK (kind IN ('group', 'article')),
                CONSTRAINT chk_balance_article_depth CHECK (level BETWEEN 1 AND 4),
                CONSTRAINT chk_balance_article_parent CHECK (parent_id IS NULL OR parent_id <> id),
                CONSTRAINT fk_balance_article_parent FOREIGN KEY (company_id, parent_id) REFERENCES balance_articles (company_id, id) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql('CREATE INDEX idx_balance_article_parent ON balance_articles (company_id, parent_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE balance_accounts (
                id UUID NOT NULL PRIMARY KEY,
                company_id UUID NOT NULL,
                article_id UUID NOT NULL,
                code VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                allow_negative BOOLEAN DEFAULT FALSE NOT NULL,
                is_archived BOOLEAN DEFAULT FALSE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT uniq_balance_account_company_id UNIQUE (company_id, id),
                CONSTRAINT uniq_balance_account_code UNIQUE (company_id, code),
                CONSTRAINT fk_balance_account_article FOREIGN KEY (company_id, article_id) REFERENCES balance_articles (company_id, id) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql('CREATE INDEX idx_balance_account_article ON balance_accounts (company_id, article_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE balance_operations (
                id UUID NOT NULL PRIMARY KEY,
                company_id UUID NOT NULL,
                number BIGINT NOT NULL,
                kind VARCHAR(20) NOT NULL,
                operation_date DATE NOT NULL,
                status VARCHAR(20) DEFAULT 'draft' NOT NULL,
                reason TEXT NOT NULL,
                author_id UUID NOT NULL,
                posted_by UUID DEFAULT NULL,
                posted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                posting_sequence BIGINT DEFAULT NULL,
                original_operation_id UUID DEFAULT NULL,
                request_key VARCHAR(128) NOT NULL,
                request_hash VARCHAR(64) NOT NULL,
                version INT DEFAULT 1 NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT uniq_balance_operation_company_id UNIQUE (company_id, id),
                CONSTRAINT uniq_balance_operation_number UNIQUE (company_id, number),
                CONSTRAINT uniq_balance_operation_request UNIQUE (company_id, request_key),
                CONSTRAINT uniq_balance_operation_sequence UNIQUE (company_id, posting_sequence),
                CONSTRAINT chk_balance_operation_kind CHECK (kind IN ('opening', 'operation', 'correction', 'reversal')),
                CONSTRAINT chk_balance_operation_status CHECK (status IN ('draft', 'posted')),
                CONSTRAINT chk_balance_operation_numbers CHECK (number > 0 AND version > 0 AND (posting_sequence IS NULL OR posting_sequence > 0)),
                CONSTRAINT chk_balance_operation_posting CHECK (
                    (status = 'draft' AND posted_by IS NULL AND posted_at IS NULL AND posting_sequence IS NULL)
                    OR (status = 'posted' AND posted_by IS NOT NULL AND posted_at IS NOT NULL AND posting_sequence IS NOT NULL)
                ),
                CONSTRAINT chk_balance_operation_reversal CHECK (kind <> 'reversal' OR original_operation_id IS NOT NULL),
                CONSTRAINT chk_balance_operation_original CHECK (original_operation_id IS NULL OR original_operation_id <> id),
                CONSTRAINT fk_balance_operation_original FOREIGN KEY (company_id, original_operation_id) REFERENCES balance_operations (company_id, id) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql("CREATE UNIQUE INDEX uniq_balance_operation_opening ON balance_operations (company_id) WHERE kind = 'opening'");
        $this->addSql("CREATE UNIQUE INDEX uniq_balance_operation_reversal ON balance_operations (company_id, original_operation_id) WHERE kind = 'reversal'");
        $this->addSql('CREATE INDEX idx_balance_operation_date ON balance_operations (company_id, operation_date, posting_sequence)');
        $this->addSql('CREATE INDEX idx_balance_operation_status ON balance_operations (company_id, status, operation_date)');
        $this->addSql('CREATE INDEX idx_balance_operation_original ON balance_operations (company_id, original_operation_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE balance_operation_lines (
                id UUID NOT NULL PRIMARY KEY,
                company_id UUID NOT NULL,
                operation_id UUID NOT NULL,
                account_id UUID NOT NULL,
                direction VARCHAR(10) NOT NULL,
                amount BIGINT NOT NULL,
                CONSTRAINT uniq_balance_line_account UNIQUE (company_id, operation_id, account_id),
                CONSTRAINT chk_balance_line_direction CHECK (direction IN ('increase', 'decrease')),
                CONSTRAINT chk_balance_line_amount CHECK (amount > 0),
                CONSTRAINT fk_balance_line_operation FOREIGN KEY (company_id, operation_id) REFERENCES balance_operations (company_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_balance_line_account FOREIGN KEY (company_id, account_id) REFERENCES balance_accounts (company_id, id) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql('CREATE INDEX idx_balance_line_account ON balance_operation_lines (company_id, account_id, operation_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE balance_account_states (
                id UUID NOT NULL PRIMARY KEY,
                company_id UUID NOT NULL,
                account_id UUID NOT NULL,
                balance BIGINT DEFAULT 0 NOT NULL,
                journal_version INT DEFAULT 0 NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT uniq_balance_state_account UNIQUE (company_id, account_id),
                CONSTRAINT chk_balance_state_version CHECK (journal_version >= 0),
                CONSTRAINT fk_balance_state_account FOREIGN KEY (company_id, account_id) REFERENCES balance_accounts (company_id, id) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE balance_periods (
                id UUID NOT NULL PRIMARY KEY,
                company_id UUID NOT NULL,
                month DATE NOT NULL,
                is_closed BOOLEAN DEFAULT FALSE NOT NULL,
                changed_by UUID NOT NULL,
                reason TEXT NOT NULL,
                changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT uniq_balance_period_month UNIQUE (company_id, month),
                CONSTRAINT chk_balance_period_month CHECK (EXTRACT(DAY FROM month) = 1)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE balance_access_grants (
                id UUID NOT NULL PRIMARY KEY,
                company_id UUID NOT NULL,
                user_id UUID NOT NULL,
                can_prepare BOOLEAN DEFAULT FALSE NOT NULL,
                can_post BOOLEAN DEFAULT FALSE NOT NULL,
                can_manage_periods BOOLEAN DEFAULT FALSE NOT NULL,
                can_reopen_periods BOOLEAN DEFAULT FALSE NOT NULL,
                CONSTRAINT uniq_balance_grant_user UNIQUE (company_id, user_id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE balance_audit_events (
                id UUID NOT NULL PRIMARY KEY,
                company_id UUID NOT NULL,
                object_type VARCHAR(40) NOT NULL,
                object_id UUID NOT NULL,
                action VARCHAR(60) NOT NULL,
                author_id UUID DEFAULT NULL,
                changes JSONB NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_balance_audit_object ON balance_audit_events (company_id, object_type, object_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping the Balance ledger destroys accounting history. Restore through a reviewed forward migration.');
    }
}
