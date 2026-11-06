<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settlement;

use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class SettlementSourceLockOrderTest extends IntegrationTestCase
{
    public function testImportPrelockWaitsForSmallestIdBeforeLockingLargerId(): void
    {
        $db = DriverManager::getConnection($this->connection->getParams());
        $schema = 'settlement_lock_'.str_replace('-', '', Uuid::uuid7()->toString());
        $company = Uuid::uuid7()->toString();
        $account = Uuid::uuid7()->toString();
        $firstId = '11111111-1111-7111-a111-111111111111';
        $secondId = '22222222-2222-7222-a222-222222222222';
        $process = null;
        $db->executeStatement('CREATE SCHEMA '.$schema);
        try {
            $db->executeStatement('SET search_path TO '.$schema);
            $db->executeStatement('CREATE TABLE cash_transaction (id UUID PRIMARY KEY, company_id UUID NOT NULL, money_account_id UUID NOT NULL)');
            // Physical insertion order intentionally opposes the required lock order.
            foreach ([$secondId, $firstId] as $id) {
                $db->insert('cash_transaction', ['id' => $id, 'company_id' => $company, 'money_account_id' => $account]);
            }
            $db->beginTransaction();
            $db->fetchOne('SELECT id FROM cash_transaction WHERE id = ? FOR UPDATE', [$firstId]);
            $input = new InputStream();
            $process = new Process([\PHP_BINARY, 'tests/Fixtures/Settlement/lock-sources.php'], (string) self::getContainer()->getParameter('kernel.project_dir'), ['SETTLEMENT_LOCK_SCHEMA' => $schema, 'SETTLEMENT_LOCK_COMPANY' => $company, 'SETTLEMENT_LOCK_ACCOUNT' => $account], $input, 15);
            $process->start();
            self::assertTrue($process->waitUntil(static fn (string $type, string $buffer): bool => str_contains($buffer, "ready\n")), $process->getErrorOutput());
            $deadline = microtime(true) + 5;
            do {
                $waiting = (bool) $db->fetchOne("SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$schema]);
                if (!$waiting) {
                    usleep(10000);
                }
            } while (!$waiting && microtime(true) < $deadline);
            self::assertTrue($waiting, 'Child must be waiting for first row: '.$process->getErrorOutput());
            // If child acquired B before A, this NOWAIT fails; sorted prelocking leaves B free.
            self::assertSame($secondId, $db->fetchOne('SELECT id FROM cash_transaction WHERE id = ? FOR UPDATE NOWAIT', [$secondId]));
            $db->commit();
            self::assertTrue($process->waitUntil(static fn (string $type, string $buffer): bool => str_contains($buffer, "locked\n")), $process->getErrorOutput());
            $input->write("stop\n");
            $input->close();
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            if (null !== $process && $process->isRunning()) {
                $process->stop(0);
            }
            $db->executeStatement('SET search_path TO public');
            $db->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
            $db->close();
        }
    }

    public function testSourceMutationWaitsForRegistrationAndSeesCommittedActiveLink(): void
    {
        $db = DriverManager::getConnection($this->connection->getParams());
        $schema = 'settlement_lock_'.str_replace('-', '', Uuid::uuid7()->toString());
        $company = Uuid::uuid7()->toString();
        $account = Uuid::uuid7()->toString();
        $source = Uuid::uuid7()->toString();
        $process = null;
        $db->executeStatement('CREATE SCHEMA '.$schema);
        try {
            $db->executeStatement('SET search_path TO '.$schema);
            $db->executeStatement('CREATE TABLE cash_transaction (id UUID PRIMARY KEY, company_id UUID NOT NULL, money_account_id UUID NOT NULL, counterparty_id UUID, amount NUMERIC(18,2), currency VARCHAR(3), occurred_at DATE, direction VARCHAR(16), deleted_at TIMESTAMP, is_transfer BOOLEAN)');
            $db->executeStatement('CREATE TABLE settlement_source_links (company_id UUID, source_type VARCHAR(16), source_id UUID, active_document_id UUID)');
            $db->insert('cash_transaction', ['id' => $source, 'company_id' => $company, 'money_account_id' => $account, 'counterparty_id' => Uuid::uuid7()->toString(), 'amount' => '40000.00', 'currency' => 'RUB', 'occurred_at' => '2026-11-01', 'direction' => 'OUTFLOW', 'is_transfer' => false], ['is_transfer' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
            $db->beginTransaction();
            $db->fetchOne('SELECT id FROM cash_transaction WHERE id = ? FOR UPDATE', [$source]);
            // Registration's active link remains invisible to another transaction until commit.
            $db->insert('settlement_source_links', ['company_id' => $company, 'source_type' => 'cash', 'source_id' => $source, 'active_document_id' => Uuid::uuid7()->toString()]);
            $process = new Process([\PHP_BINARY, 'tests/Fixtures/Settlement/lock-sources.php'], (string) self::getContainer()->getParameter('kernel.project_dir'), ['SETTLEMENT_LOCK_SCHEMA' => $schema, 'SETTLEMENT_LOCK_COMPANY' => $company, 'SETTLEMENT_LOCK_ACCOUNT' => $account, 'SETTLEMENT_LOCK_SOURCE' => $source, 'SETTLEMENT_LOCK_MODE' => 'guard'], null, 15);
            $process->start();
            self::assertTrue($process->waitUntil(static fn (string $type, string $buffer): bool => str_contains($buffer, "ready\n")), $process->getErrorOutput());
            $deadline = microtime(true) + 5;
            do {
                $waiting = (bool) $db->fetchOne("SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$schema]);
                if (!$waiting) {
                    usleep(10000);
                }
            } while (!$waiting && microtime(true) < $deadline);
            self::assertTrue($waiting, 'Mutation must wait for source registration: '.$process->getErrorOutput());
            $db->commit();
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertStringContainsString("blocked\n", $process->getOutput());
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            if (null !== $process && $process->isRunning()) {
                $process->stop(0);
            }
            $db->executeStatement('SET search_path TO public');
            $db->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
            $db->close();
        }
    }
}
