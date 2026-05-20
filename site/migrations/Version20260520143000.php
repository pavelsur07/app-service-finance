<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marketplace: add PostgreSQL-only company-aware partial unique business keys for sales/returns/costs with duplicate audit guards';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $this->abortIf($platform !== 'postgresql', 'Migration can only be executed safely on postgresql.');

        $salesDuplicates = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM (
                SELECT s.company_id, s.marketplace, s.external_order_id
                FROM marketplace_sales s
                WHERE s.external_order_id IS NOT NULL
                  AND TRIM(s.external_order_id) <> ''
                GROUP BY s.company_id, s.marketplace, s.external_order_id
                HAVING COUNT(*) > 1
            ) dup
        SQL);

        $returnsDuplicates = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM (
                SELECT r.company_id, r.marketplace, r.external_return_id
                FROM marketplace_returns r
                WHERE r.external_return_id IS NOT NULL
                  AND TRIM(r.external_return_id) <> ''
                GROUP BY r.company_id, r.marketplace, r.external_return_id
                HAVING COUNT(*) > 1
            ) dup
        SQL);

        $costsDuplicates = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM (
                SELECT c.company_id, c.marketplace, c.external_id
                FROM marketplace_costs c
                WHERE c.external_id IS NOT NULL
                  AND TRIM(c.external_id) <> ''
                GROUP BY c.company_id, c.marketplace, c.external_id
                HAVING COUNT(*) > 1
            ) dup
        SQL);

        $this->abortIf(
            $salesDuplicates > 0,
            sprintf('Migration aborted: found %d duplicate business keys in marketplace_sales (company_id, marketplace, external_order_id where non-empty). Resolve duplicates and rerun migration.', $salesDuplicates),
        );

        $this->abortIf(
            $returnsDuplicates > 0,
            sprintf('Migration aborted: found %d duplicate business keys in marketplace_returns (company_id, marketplace, external_return_id where non-empty). Resolve duplicates and rerun migration.', $returnsDuplicates),
        );

        $this->abortIf(
            $costsDuplicates > 0,
            sprintf('Migration aborted: found %d duplicate business keys in marketplace_costs (company_id, marketplace, external_id where non-empty). Resolve duplicates and rerun migration.', $costsDuplicates),
        );

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_marketplace_sales_company_marketplace_external_order
            ON marketplace_sales (company_id, marketplace, external_order_id)
            WHERE external_order_id IS NOT NULL AND TRIM(external_order_id) <> ''
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_marketplace_returns_company_marketplace_external_return
            ON marketplace_returns (company_id, marketplace, external_return_id)
            WHERE external_return_id IS NOT NULL AND TRIM(external_return_id) <> ''
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_marketplace_costs_company_marketplace_external
            ON marketplace_costs (company_id, marketplace, external_id)
            WHERE external_id IS NOT NULL AND TRIM(external_id) <> ''
        SQL);
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $this->abortIf($platform !== 'postgresql', 'Migration can only be executed safely on postgresql.');

        $this->addSql('DROP INDEX IF EXISTS uniq_marketplace_sales_company_marketplace_external_order');
        $this->addSql('DROP INDEX IF EXISTS uniq_marketplace_returns_company_marketplace_external_return');
        $this->addSql('DROP INDEX IF EXISTS uniq_marketplace_costs_company_marketplace_external');
    }
}
