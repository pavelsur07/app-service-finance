<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Индекс на marketplace_sales.raw_document_id.
 *
 * OzonSalesRawProcessor::process() чистит записи по raw_document_id перед
 * повторной обработкой (и OzonCostsRawProcessor делает аналогично — там индекс
 * idx_cost_raw_document уже существует). Без индекса DELETE превращается в
 * sequential scan по всей таблице.
 *
 * Парный индекс на marketplace_returns.raw_document_id не добавляется — там
 * DELETE по этому столбцу пока не выполняется.
 */
final class Version20260414000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index on marketplace_sales.raw_document_id to support DELETE by raw document during reprocessing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_sale_raw_document ON marketplace_sales (raw_document_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_sale_raw_document');
    }
}
