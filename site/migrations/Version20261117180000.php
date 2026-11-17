<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cash: cashflow_group_chart_applications — журнал синхронизаций дерева ДДС с эталоном группы.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cashflow_group_chart_applications (id UUID NOT NULL, company_id UUID NOT NULL, source VARCHAR(8) NOT NULL, head_company_id UUID DEFAULT NULL, bound_count INT NOT NULL, created_count INT NOT NULL, conflict_count INT NOT NULL, head_codes_count INT NOT NULL, applied_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_cashflow_group_chart_applications_company ON cashflow_group_chart_applications (company_id, applied_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cashflow_group_chart_applications');
    }
}
