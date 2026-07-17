<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260717130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable responsibility center target to Cash auto rules';
    }

    public function up(Schema $schema): void
    {
        $this->assertPostgreSQL();

        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD responsibility_center_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_ctar_responsibility_center ON cash_transaction_auto_rule (responsibility_center_id)');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule ADD CONSTRAINT fk_ctar_responsibility_center FOREIGN KEY (responsibility_center_id) REFERENCES financial_responsibility_centers (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->assertPostgreSQL();

        $configuredRuleCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM cash_transaction_auto_rule WHERE responsibility_center_id IS NOT NULL',
        );
        if ($configuredRuleCount > 0) {
            $this->throwIrreversibleMigrationException(
                'Cash auto-rule responsibility-center targets are configured; rollback would discard user data.',
            );
        }

        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP CONSTRAINT fk_ctar_responsibility_center');
        $this->addSql('DROP INDEX idx_ctar_responsibility_center');
        $this->addSql('ALTER TABLE cash_transaction_auto_rule DROP COLUMN responsibility_center_id');
    }

    private function assertPostgreSQL(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );
    }
}
