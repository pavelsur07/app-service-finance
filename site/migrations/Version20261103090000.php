<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WB finance cursor next_rrd_id: INT -> BIGINT (rrd_id exceeded int4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_financial_report_sync_statuses ALTER COLUMN next_rrd_id TYPE BIGINT');
    }

    public function down(Schema $schema): void
    {
        // Откат возможен, только пока ни один курсор не превышает int4; иначе Postgres
        // откажет с out of range — данные не обрезаются молча.
        $this->addSql('ALTER TABLE marketplace_financial_report_sync_statuses ALTER COLUMN next_rrd_id TYPE INT');
    }
}
