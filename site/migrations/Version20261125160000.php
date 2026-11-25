<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cash: ручные решения по сверке внутригрупповых операций ДДС.';
    }

    public function up(Schema $schema): void
    {
        // Ссылки на операции сохраняются после удаления исходной записи для разбора устаревшего решения.
        $this->addSql(<<<'SQL'
            CREATE TABLE cash_intragroup_decision (
                id UUID NOT NULL PRIMARY KEY,
                company_id UUID NOT NULL,
                type VARCHAR(6) NOT NULL CHECK (type IN ('PAIR', 'UNPAIR')),
                outflow_transaction_id UUID NOT NULL,
                inflow_transaction_id UUID NOT NULL,
                created_by UUID NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CHECK (outflow_transaction_id <> inflow_transaction_id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_cash_intragroup_decision_company ON cash_intragroup_decision (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_cash_intragroup_decision_legs ON cash_intragroup_decision (type, outflow_transaction_id, inflow_transaction_id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_cash_intragroup_pair_out ON cash_intragroup_decision (outflow_transaction_id) WHERE type = 'PAIR'");
        $this->addSql("CREATE UNIQUE INDEX uniq_cash_intragroup_pair_in ON cash_intragroup_decision (inflow_transaction_id) WHERE type = 'PAIR'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cash_intragroup_decision');
    }
}
