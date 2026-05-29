<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260529120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add durable WB finance pagination cursor to sync statuses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_financial_report_sync_statuses ADD staging_raw_document_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_financial_report_sync_statuses ADD next_rrd_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_financial_report_sync_statuses DROP staging_raw_document_id');
        $this->addSql('ALTER TABLE marketplace_financial_report_sync_statuses DROP next_rrd_id');
    }
}
