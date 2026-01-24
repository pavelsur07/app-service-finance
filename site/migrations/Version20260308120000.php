<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260308120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop Ozon and Wildberries marketplace tables';
    }

    public function up(Schema $schema): void
    {
        $tables = [
            'ozon_order_items',
            'ozon_order_status_history',
            'ozon_orders',
            'ozon_product_sales',
            'ozon_product_stocks',
            'ozon_sync_cursor',
            'ozon_products',
            'wildberries_commissioner_cost_mappings',
            'wildberries_commissioner_aggregation_results',
            'wildberries_commissioner_report_rows_raw',
            'wildberries_commissioner_dimension_values',
            'wildberries_commissioner_cost_types',
            'wildberries_commissioner_xlsx_reports',
            'wildberries_report_detail_mappings',
            'wildberries_report_details',
            'wildberries_import_log',
            'wildberries_rnp_daily',
            'wildberries_sales',
        ];

        foreach ($tables as $table) {
            if ($schema->hasTable($table)) {
                $this->addSql(sprintf('DROP TABLE %s', $table));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Marketplace tables have been dropped.');
    }
}
