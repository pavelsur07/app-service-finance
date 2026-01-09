<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250921000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add document and transfer metadata to cash_transaction';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE cash_transaction ADD doc_type VARCHAR(64) DEFAULT NULL");
        $this->addSql("ALTER TABLE cash_transaction ADD doc_number VARCHAR(64) DEFAULT NULL");
        $this->addSql("ALTER TABLE cash_transaction ADD is_transfer BOOLEAN NOT NULL DEFAULT false");
        $this->addSql("ALTER TABLE cash_transaction ADD raw_data JSON NOT NULL DEFAULT '[]'");
        $this->addSql("ALTER TABLE cash_transaction ALTER external_id TYPE VARCHAR(128)");
        $this->addSql(<<<'SQL'
WITH ranked AS (
    SELECT
        id,
        ROW_NUMBER() OVER (PARTITION BY external_id ORDER BY created_at, id) AS rn
    FROM cash_transaction
    WHERE external_id IS NOT NULL
)
UPDATE cash_transaction ct
SET external_id = NULL
FROM ranked r
WHERE ct.id = r.id AND r.rn > 1;
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_cash_transaction_external_id ON cash_transaction (external_id)');
        $this->addSql("ALTER TABLE cash_transaction ALTER COLUMN is_transfer DROP DEFAULT");
        $this->addSql("ALTER TABLE cash_transaction ALTER COLUMN raw_data DROP DEFAULT");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_cash_transaction_external_id');
        $this->addSql("ALTER TABLE cash_transaction ALTER external_id TYPE VARCHAR(255)");
        $this->addSql('ALTER TABLE cash_transaction DROP COLUMN raw_data');
        $this->addSql('ALTER TABLE cash_transaction DROP COLUMN is_transfer');
        $this->addSql('ALTER TABLE cash_transaction DROP COLUMN doc_number');
        $this->addSql('ALTER TABLE cash_transaction DROP COLUMN doc_type');
    }
}
