<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settlement;

use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;

final class SettlementAllocationConcurrencyTest extends IntegrationTestCase
{
    public function testIndependentSessionsCannotDoubleSpendOrDoubleReverse(): void
    {
        $db = DriverManager::getConnection($this->connection->getParams());
        $schema = 'settlement_lock_'.str_replace('-', '', Uuid::uuid7()->toString());
        $company = Uuid::uuid7()->toString();
        $actor = Uuid::uuid7()->toString();
        $party = Uuid::uuid7()->toString();
        $db->executeStatement('CREATE SCHEMA '.$schema);
        try {
            $db->executeStatement('SET search_path TO '.$schema);
            $migrationFile = dirname(__DIR__, 3).'/migrations/Version20261106183000.php';
            require_once $migrationFile;
            $migrationClass = 'DoctrineMigrations\\'.basename($migrationFile, '.php');
            if (!is_subclass_of($migrationClass, \Doctrine\Migrations\AbstractMigration::class)) {
                throw new \LogicException('Invalid fixture migration.');
            }
            $migration = new $migrationClass($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement());
            }
            $db->executeStatement('CREATE TABLE companies (id UUID PRIMARY KEY, user_id UUID NOT NULL, finance_lock_before DATE)');
            $db->insert('companies', ['id' => $company, 'user_id' => $actor]);
            $db->insert('settlement_books', ['id' => Uuid::uuid7()->toString(), 'company_id' => $company, 'start_date' => '2026-11-01', 'initialized' => 1, 'version' => 1, 'next_event_sequence' => 3]);
            $items = [];
            foreach (['payable' => '10000', 'advance_given' => '4000'] as $bucket => $amount) {
                $document = Uuid::uuid7()->toString();
                $item = Uuid::uuid7()->toString();
                $items[] = $item;
                $db->insert('settlement_documents', ['id' => $document, 'company_id' => $company, 'request_id' => Uuid::uuid7()->toString(), 'kind' => 'opening', 'counterparty_id' => $party, 'bucket' => $bucket, 'effective_date' => '2026-11-01', 'reason' => 'Fixture', 'actor_user_id' => $actor, 'source_type' => 'manual', 'source_snapshot' => '{}', 'status' => 'posted', 'version' => 2, 'event_sequence' => count($items), 'amount_amount_minor' => $amount, 'amount_currency' => 'RUB']);
                $db->insert('settlement_items', ['id' => $item, 'company_id' => $company, 'document_id' => $document, 'counterparty_id' => $party, 'bucket' => $bucket, 'effective_date' => '2026-11-01', 'amount_amount_minor' => $amount, 'amount_currency' => 'RUB']);
                $db->insert('settlement_movements', ['id' => Uuid::uuid7()->toString(), 'company_id' => $company, 'document_id' => $document, 'item_id' => $item, 'bucket' => $bucket, 'effective_date' => '2026-11-01', 'delta_amount_minor' => $amount, 'delta_currency' => 'RUB']);
            }
            $environment = ['SETTLEMENT_LOCK_SCHEMA' => $schema, 'SETTLEMENT_LOCK_COMPANY' => $company, 'SETTLEMENT_LOCK_ACTOR' => $actor, 'SETTLEMENT_LOCK_DEBT' => $items[0], 'SETTLEMENT_LOCK_PAYMENT' => $items[1]];
            $results = $this->race($db, $environment);
            self::assertCount(1, array_filter($results, static fn (string $out): bool => str_contains($out, 'success:')));
            self::assertCount(1, array_filter($results, static fn (string $out): bool => str_contains($out, 'rejected:')));
            self::assertSame('1000', (string) $db->fetchOne("SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id=? AND bucket='advance_given'", [$company]));
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM settlement_allocations WHERE company_id=?', [$company]));
            $allocation = (string) $db->fetchOne('SELECT id FROM settlement_allocations WHERE company_id=?', [$company]);
            $reversals = $this->race($db, $environment + ['SETTLEMENT_LOCK_MODE' => 'reverse', 'SETTLEMENT_LOCK_ALLOCATION' => $allocation]);
            preg_match('/success:([a-f0-9-]+)/', $reversals[0], $first);
            preg_match('/success:([a-f0-9-]+)/', $reversals[1], $second);
            self::assertNotEmpty($first);
            self::assertSame($first[1], $second[1] ?? null);
            self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM settlement_documents WHERE company_id=? AND kind='allocation_reversal'", [$company]));
            self::assertSame('4000', (string) $db->fetchOne("SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id=? AND bucket='advance_given'", [$company]));
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $db->executeStatement('SET search_path TO public');
            $db->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
            $db->close();
        }
    }

    /** @param array<string,string> $environment
     * @return list<string>
     */
    private function race(Connection $db, array $environment): array
    {
        $processes = [];
        $db->beginTransaction();
        $db->fetchOne('SELECT id FROM settlement_books WHERE company_id=? FOR UPDATE', [$environment['SETTLEMENT_LOCK_COMPANY']]);
        try {
            for ($i = 0; $i < 2; ++$i) {
                $process = new Process([\PHP_BINARY, 'tests/Fixtures/Settlement/mutate-allocation.php'], (string) self::getContainer()->getParameter('kernel.project_dir'), $environment, null, 15);
                $process->start();
                $processes[] = $process;
                self::assertTrue($process->waitUntil(static fn (string $type, string $buffer): bool => str_contains($buffer, "ready\n")), $process->getErrorOutput());
            }
            $deadline = microtime(true) + 5;
            do {
                $waiting = (int) $db->fetchOne("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [$environment['SETTLEMENT_LOCK_SCHEMA']]);
                if (2 !== $waiting) {
                    usleep(10000);
                }
            } while (2 !== $waiting && microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both independent child transactions must contend before release.');
            $db->commit();
            $results = [];
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = $process->getOutput();
            }

            return $results;
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }
}
