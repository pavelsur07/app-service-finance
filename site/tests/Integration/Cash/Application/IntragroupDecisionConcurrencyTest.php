<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\Report\IntragroupDecisionAction;
use App\Cash\Enum\Transaction\IntragroupDecisionType;
use App\Tests\Fixtures\Cash\IntragroupReportFixture;
use App\Tests\Support\Kernel\IntegrationTestCase;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;

final class IntragroupDecisionConcurrencyTest extends IntegrationTestCase
{
    use IntragroupReportFixture;

    /** @return iterable<string, array{bool}> */
    public static function concurrentSubmissions(): iterable
    {
        yield 'повтор того же POST' => [true];
        yield 'другая пара с той же выплатой' => [false];
    }

    #[SkipDatabaseRollback]
    #[DataProvider('concurrentSubmissions')]
    public function testConcurrentPairRequestsSerializeBeforeCheckingTheDecision(bool $retry): void
    {
        $this->connection->transactional(function (): void {
            $this->createGroupFixture();
            $this->fillMissingInns();
            $this->addTransaction(971, '0001', '2026-11-17', '-100.00', 'ООО "Бета"', 'OP_OUT_OTHER');
            $this->addTransaction(972, '0003', '2026-11-18', '100.00', 'ООО "Альфа"', 'OP_IN_OTHER');
            $this->addTransaction(973, '0003', '2026-11-16', '100.00', 'ООО "Альфа"', 'OP_IN_OTHER');
            $this->em->flush();
        });
        $head = $this->companies['Альфа'];
        $headId = (string) $head->getId();
        $userId = (string) $head->getUser()?->getId();
        $first = Uuid::uuid7()->toString();
        $second = $retry ? $first : Uuid::uuid7()->toString();
        $out = self::transactionId(971);
        $in = self::transactionId(972);
        $worker = new Process([\PHP_BINARY, 'tests/Fixtures/Cash/save-intragroup-decision.php', $headId, $userId, $second, $out, $retry ? $in : self::transactionId(973)], (string) self::getContainer()->getParameter('kernel.project_dir'), timeout: 20);
        try {
            $this->connection->beginTransaction();
            self::getContainer()->get(IntragroupDecisionAction::class)->save($headId, $userId, $first, IntragroupDecisionType::PAIR, $out, $in);
            $worker->start();
            self::assertTrue($worker->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "ready\n")), $worker->getErrorOutput());

            $deadline = microtime(true) + 5;
            do {
                $waiting = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND pg_backend_pid() = ANY(pg_blocking_pids(pid))', ['intragroup-'.$second]);
                if (!$waiting) {
                    usleep(10000);
                }
            } while (!$waiting && $worker->isRunning() && microtime(true) < $deadline);
            self::assertTrue($waiting, 'Второй запрос должен ждать commit первого.');
            $this->connection->commit();
            self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
            self::assertStringContainsString($retry ? 'saved' : 'Операция уже входит в ручную пару.', $worker->getOutput());
            self::assertSame([$first => $in], $this->connection->fetchAllKeyValue('SELECT id, inflow_transaction_id FROM cash_intragroup_decision WHERE company_id = ?', [$headId]));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(0);
            }
            $this->removeCommittedGroupFixture();
        }
    }
}
