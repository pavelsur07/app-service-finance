<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Один плановый платёж — одно сопоставление с транзакцией ДДС. Данные не меняются: план с двумя
 * сопоставлениями — повод остановиться и разобрать вручную, а не выбирать победителя миграцией.
 */
final class Version20261023090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unique payment_plan_match (plan_id): one cash transaction per payment plan';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $duplicates = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT plan_id FROM payment_plan_match GROUP BY plan_id HAVING COUNT(*) > 1) duplicated',
        );
        $this->abortIf(
            $duplicates > 0,
            sprintf('payment_plan_match: %d plan(s) matched more than once; resolve them before adding the unique index.', $duplicates),
        );

        $this->addSql('CREATE UNIQUE INDEX uniq_payment_plan_match_plan ON payment_plan_match (plan_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $this->addSql('DROP INDEX uniq_payment_plan_match_plan');
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
