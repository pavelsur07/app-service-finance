<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Происхождение дерева ОПиУ: каждое применение стартового шаблона, создавшее статьи. Новая пустая
 * таблица — данные не переносятся; у существующих компаний происхождение не записано.
 */
final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pl_template_applications (origin of the company P&L tree)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $this->addSql('CREATE TABLE pl_template_applications (id UUID NOT NULL, company_id UUID NOT NULL, profile VARCHAR(32) NOT NULL, template_version INT NOT NULL, created_count INT NOT NULL, source VARCHAR(16) NOT NULL, applied_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_pl_template_applications_company ON pl_template_applications (company_id, applied_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $this->addSql('DROP TABLE pl_template_applications');
    }

    private function abortIfNotPostgres(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );
    }
}
