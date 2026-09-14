# Balance ledger schema contract

All tables have UUID `id` primary key and non-null UUID `company_id`. Monetary values are exact BIGINT minimal currency units; Doctrine BIGINT maps to string. Timestamps are immutable, without timezone as in existing mappings. New IDs are UUIDv7. No legacy table is modified or read by new accounting.

| Table | Additional columns |
|---|---|
| `balance_books` | `currency varchar(3) NULL`, `start_date date NULL`, `initialized boolean DEFAULT false`, `version integer DEFAULT 0`, `next_document_number bigint DEFAULT 1`, `next_posting_sequence bigint DEFAULT 1`, `created_at timestamp`, `updated_at timestamp` |
| `balance_articles` | Root-owned category fields: `name varchar(255)`, `code varchar(64) NULL`, `type varchar(50)` asset/passive, `parent_id uuid NULL`, `level integer` 1..4, `sort_order integer DEFAULT 0`, `is_visible boolean DEFAULT true`, `kind varchar(20)` group/article, `is_archived boolean DEFAULT false`, `created_at timestamp`, `updated_at timestamp` |
| `balance_accounts` | `article_id uuid`, `code varchar(64)`, `name varchar(255)`, `allow_negative boolean DEFAULT false`, `is_archived boolean DEFAULT false`, `created_at timestamp`, `updated_at timestamp` |
| `balance_operations` | `number bigint`, `kind varchar(20)` opening/operation/correction/reversal, `operation_date date`, `status varchar(20) DEFAULT 'draft'` draft/posted, `reason text`, `author_id uuid`, `posted_by uuid NULL`, `posted_at timestamp NULL`, `posting_sequence bigint NULL`, `original_operation_id uuid NULL`, `request_key varchar(128)`, `request_hash varchar(64)`, `version integer DEFAULT 1`, `created_at timestamp`, `updated_at timestamp` |
| `balance_operation_lines` | `operation_id uuid`, `account_id uuid`, `direction varchar(10)` increase/decrease, `amount bigint` positive |
| `balance_account_states` | `account_id uuid`, `balance bigint DEFAULT 0`, `journal_version integer DEFAULT 0`, `updated_at timestamp` |
| `balance_periods` | `month date` first day of month, `is_closed boolean DEFAULT false`, `changed_by uuid`, `reason text`, `changed_at timestamp` |
| `balance_access_grants` | `user_id uuid`, `can_prepare boolean DEFAULT false`, `can_post boolean DEFAULT false`, `can_manage_periods boolean DEFAULT false`, `can_reopen_periods boolean DEFAULT false` |
| `balance_audit_events` | `object_type varchar(40)`, `object_id uuid`, `action varchar(60)`, `author_id uuid NULL` (trusted system seed only), `changes jsonb`, `created_at timestamp` |

Company-scoped unique constraints: book(company_id), article(company_id,code), account(company_id,code), operation(company_id,number), operation(company_id,request_key), operation(company_id,posting_sequence), line(company_id,operation_id,account_id), state(company_id,account_id), period(company_id,month), grant(company_id,user_id). One opening operation per company, one reversal per source operation (partial unique indexes). Composite (company_id,id) uniqueness supports same-company foreign keys article→parent, account→article, operation→original, line→operation/account, state→account. No cascade removal of financial history.

Business actions must explicitly update version, timestamps and audit rows. DBAL actions take company `FOR SHARE` through CompanyFacade for fresh permission checks, then hold `balance_books` row `FOR UPDATE` for posting, closing and related accounting mutations. ORM entities are constructor-initialized read models with scalar getters; no direct business mutation methods. Partial uniqueness and cross-company FKs are migration-managed constraints.
