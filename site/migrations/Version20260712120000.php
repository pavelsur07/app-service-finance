<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make cashflow category code unique within a company';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );

        $duplicateCount = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM (
                SELECT company_id, system_code
                FROM cashflow_categories
                WHERE system_code IS NOT NULL
                GROUP BY company_id, system_code
                HAVING COUNT(*) > 1
            ) duplicates
            SQL);

        $this->abortIf(
            $duplicateCount > 0,
            sprintf('Found %d duplicate cashflow category code(s) within companies.', $duplicateCount),
        );

        $this->addSql('CREATE UNIQUE INDEX uniq_cashflow_category_company_code ON cashflow_categories (company_id, system_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_cashflow_category_company_code');
    }
}
