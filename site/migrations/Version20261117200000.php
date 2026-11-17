<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cash: первое наблюдение конфликта статьи по операции и версии автоправила.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cash_auto_rule_category_conflict (
                company_id UUID NOT NULL,
                rule_id UUID NOT NULL,
                revision INT NOT NULL,
                transaction_id UUID NOT NULL,
                observed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (company_id, rule_id, revision, transaction_id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_cash_rule_conflict_period ON cash_auto_rule_category_conflict (company_id, rule_id, observed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cash_auto_rule_category_conflict');
    }
}
